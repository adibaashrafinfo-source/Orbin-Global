<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\ProductVariant;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockLevel;
use App\Models\StorageLocation;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\NumberService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * FR-406. draft → counting → review → posted. The list is frozen from the stock on file when counting starts. Posting
 * corrects the shelf to what was counted, measured against the stock on file at that moment (so a sale rung up while
 * counting is not corrected twice).
 */
class StockCountService
{
    public function __construct(
        private readonly NumberService $numbers,
        private readonly AuditService $audit,
        private readonly StockPostingService $stock,
        private readonly PeriodLockService $period,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): StockCount
    {
        $warehouse = Warehouse::query()->findOrFail((int) $data['warehouse_id']);
        $scope = $data['scope'] ?? 'full';
        if ($scope !== 'full' && empty($data['scope_ref'])) {
            throw new ApiException('VALIDATION', 422, '', ['scope_ref' => ['errors.REQUIRED']]);
        }
        if ($scope === 'location' && ! StorageLocation::query()->where(['id' => $data['scope_ref'], 'warehouse_id' => $warehouse->id])->exists()) {
            throw new ApiException('VALIDATION', 422, '', ['scope_ref' => ['errors.VALIDATION']]);
        }

        return DB::transaction(fn () => $this->fresh(StockCount::query()->create([
            'branch_id' => $warehouse->branch_id, 'count_no' => $this->numbers->next('count', $warehouse->branch_id), 'warehouse_id' => $warehouse->id,
            'scope' => $scope, 'scope_ref' => $scope === 'full' ? null : $data['scope_ref'], 'is_blind' => (bool) ($data['is_blind'] ?? false), 'status' => 'draft', 'note' => $data['note'] ?? null,
        ])), 3);
    }

    /** Freeze the list and the system quantities; counting begins. */
    public function start(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $c = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($c, ['draft']);
            $levels = StockLevel::query()->where('warehouse_id', $c->warehouse_id)->with('variant.product')->orderBy('variant_id')->get()
                ->filter(fn ($l) => $this->inScope($c, $l));
            if ($levels->isEmpty()) {
                throw new ApiException('EMPTY_RECEIPT', 422, '', [], ['reason' => 'nothing_to_count']);
            }
            foreach ($levels as $l) {
                StockCountItem::query()->create(['count_id' => $c->id, 'variant_id' => $l->variant_id, 'system_qty' => $l->qty_on_hand, 'unit_cost' => $l->avg_cost]);
            }
            $c->update(['status' => 'counting', 'started_at' => now()]);
            $this->audit->log('count_start', 'stock_counts', $c->id, ['status' => 'draft'], ['status' => 'counting', 'items' => $levels->count()]);

            return $this->fresh($c);
        }, 3);
    }

    /** Add a variant that turned up on the shelf but was not on the list. */
    public function addItem(StockCount $count, int $variantId): StockCount
    {
        return DB::transaction(function () use ($count, $variantId): StockCount {
            $c = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($c, ['counting']);
            $variant = ProductVariant::query()->findOrFail($variantId);
            if ($variant->is_bundle) {
                throw new ApiException('BUNDLE_NO_STOCK', 422, '', [], ['sku' => $variant->sku]);
            }
            if (! $c->items()->where('variant_id', $variantId)->exists()) {
                $level = StockLevel::query()->where(['variant_id' => $variantId, 'warehouse_id' => $c->warehouse_id])->first();
                StockCountItem::query()->create(['count_id' => $c->id, 'variant_id' => $variantId, 'system_qty' => $level->qty_on_hand ?? '0.000', 'unit_cost' => $level->avg_cost ?? $variant->last_cost]);
            }

            return $this->fresh($c);
        });
    }

    /** @param  array<int, array{variant_id: int, counted_qty: string|null, note?: string|null}>  $rows */
    public function enter(StockCount $count, array $rows): StockCount
    {
        return DB::transaction(function () use ($count, $rows): StockCount {
            $c = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($c, ['counting', 'review']);
            foreach ($rows as $row) {
                $item = $c->items()->where('variant_id', (int) $row['variant_id'])->first();
                if (! $item) {
                    throw new ApiException('VALIDATION', 422, '', ['items' => ['errors.NOT_ON_COUNT_LIST']]);
                }
                $qty = $row['counted_qty'] === null || $row['counted_qty'] === '' ? null : Dec::fmt(Dec::parse((string) $row['counted_qty'], Dec::QTY), Dec::QTY);
                $item->update(['counted_qty' => $qty, 'note' => $row['note'] ?? $item->note]);
            }
            if ($c->status === 'review') {
                $c->update(['status' => 'counting']);
            }

            return $this->fresh($c);
        });
    }

    /** Everything must be counted before the variances can be reviewed. */
    public function review(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $c = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($c, ['counting']);
            $missing = $c->items()->whereNull('counted_qty')->count();
            if ($missing > 0) {
                throw new ApiException('INCOMPLETE_COUNT', 422, '', [], ['uncounted' => $missing]);
            }
            $c->update(['status' => 'review']);

            return $this->fresh($c);
        });
    }

    public function post(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $c = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($c, ['review']);
            $this->period->assertOpen(Branches::today());
            $items = $c->items()->orderBy('variant_id')->get();
            if ($items->contains(fn ($i) => $i->counted_qty === null)) {
                throw new ApiException('INCOMPLETE_COUNT', 422);
            }
            // Lock the shelf first, then measure against what is on file now.
            $lines = [];
            $meta = [];
            foreach ($items as $item) {
                $current = StockLevel::query()->where(['variant_id' => $item->variant_id, 'warehouse_id' => $c->warehouse_id])->lockForUpdate()->first();
                $now = $current ? Dec::parse((string) $current->qty_on_hand, Dec::QTY) : 0;
                $diff = Dec::parse((string) $item->counted_qty, Dec::QTY) - $now;
                $meta[$item->id] = $diff;
                if ($diff !== 0) {
                    $lines[] = ['variant_id' => $item->variant_id, 'warehouse_id' => $c->warehouse_id, 'type' => 'count_correction', 'qty' => Dec::fmt($diff, Dec::QTY),
                        'ref_table' => 'stock_counts', 'ref_id' => $c->id, 'ref_no' => $c->count_no, 'reason' => 'Stock count', 'occurred_at' => now()];
                }
            }
            $this->stock->postMany($lines);
            $c->update(['status' => 'posted', 'posted_at' => now()]);
            $this->audit->log('stock_adjust', 'stock_counts', $c->id, ['status' => 'review'], ['status' => 'posted', 'count_no' => $c->count_no, 'corrections' => count($lines)]);

            return $this->fresh($c);
        }, 3);
    }

    public function cancel(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $c = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($c, ['draft', 'counting', 'review']);
            $c->update(['status' => 'cancelled']);

            return $this->fresh($c);
        });
    }

    public function delete(StockCount $count): void
    {
        $this->assertStatus($count, ['draft', 'cancelled']);
        $count->items()->delete();
        $count->delete();
    }

    private function inScope(StockCount $c, StockLevel $level): bool
    {
        if (! $level->variant || $level->variant->is_bundle) {
            return false;
        }

        return match ($c->scope) {
            'category' => in_array($level->variant->product?->category_id, $this->categoryTree((int) $c->scope_ref), true),
            'location' => (int) $level->variant->default_location_id === (int) $c->scope_ref,
            default => true,
        };
    }

    /** @return array<int, int> */
    private function categoryTree(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier) {
            $frontier = DB::table('categories')->whereIn('parent_id', $frontier)->whereNull('deleted_at')->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** @param  array<int, string>  $from */
    private function assertStatus(StockCount $c, array $from): void
    {
        if (! in_array($c->status, $from, true)) {
            throw new ApiException('INVALID_STATE', 409, '', [], ['status' => $c->status]);
        }
    }

    private function fresh(StockCount $c): StockCount
    {
        return $c->refresh()->load(['warehouse', 'items.variant']);
    }
}
