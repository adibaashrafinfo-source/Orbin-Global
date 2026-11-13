<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\LookupValue;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\NumberService;
use App\Services\SettingsService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FR-403 and FR-404. A reason is mandatory, big adjustments wait for a second person (by quantity or value), and
 * breakage lines feed the breakage register. draft → pending_approval → posted, or rejected.
 */
class StockAdjustmentService
{
    public function __construct(
        private readonly NumberService $numbers,
        private readonly AuditService $audit,
        private readonly StockPostingService $stock,
        private readonly PeriodLockService $period,
        private readonly SettingsService $settings,
        private readonly NotifyService $notify,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): StockAdjustment
    {
        return DB::transaction(function () use ($data): StockAdjustment {
            $warehouse = Warehouse::query()->findOrFail((int) $data['warehouse_id']);
            $this->assertReason((int) $data['reason_id']);
            $adj = StockAdjustment::query()->create([
                'branch_id' => $warehouse->branch_id, 'adj_no' => $this->numbers->next('adjustment', $warehouse->branch_id), 'warehouse_id' => $warehouse->id,
                'status' => 'draft', 'reason_id' => $data['reason_id'], 'note' => $data['note'] ?? null,
            ]);
            $this->writeItems($adj, $data['items']);

            return $this->fresh($adj);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function update(StockAdjustment $adj, array $data): StockAdjustment
    {
        return DB::transaction(function () use ($adj, $data): StockAdjustment {
            $adj = StockAdjustment::query()->whereKey($adj->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($adj, ['draft', 'rejected']);
            if (isset($data['reason_id'])) {
                $this->assertReason((int) $data['reason_id']);
            }
            $adj->update(array_intersect_key($data, array_flip(['reason_id', 'note'])) + ['status' => 'draft']);
            if (isset($data['items'])) {
                $this->writeItems($adj, $data['items']);
            }

            return $this->fresh($adj);
        }, 3);
    }

    /** Ask a second person to approve (also what a storekeeper does when the adjustment is over the limit). */
    public function submit(StockAdjustment $adj): StockAdjustment
    {
        return DB::transaction(function () use ($adj): StockAdjustment {
            $adj = StockAdjustment::query()->whereKey($adj->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($adj, ['draft']);
            $adj->update(['status' => 'pending_approval']);
            $this->notify->roles(['manager', 'super_admin'], 'approval_pending', 'warning', 'Stock adjustment '.$adj->adj_no.' needs approval', 'Value '.$adj->total_value, 'stock_adjustments', $adj->id);
            $this->audit->log('submit', 'stock_adjustments', $adj->id, ['status' => 'draft'], ['status' => 'pending_approval']);

            return $this->fresh($adj);
        });
    }

    /** Post now. Over the limit needs approval permission, otherwise APPROVAL_REQUIRED (use submit instead). */
    public function post(StockAdjustment $adj, bool $canApprove): StockAdjustment
    {
        return DB::transaction(function () use ($adj, $canApprove): StockAdjustment {
            $adj = StockAdjustment::query()->whereKey($adj->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($adj, ['draft', 'pending_approval']);
            if ($adj->requires_approval && ! $canApprove) {
                throw new ApiException('APPROVAL_REQUIRED', 422, '', [], ['status' => $adj->status]);
            }
            if ($adj->status === 'pending_approval' && ! $canApprove) {
                throw ApiException::forbidden();
            }

            return $this->apply($adj, $adj->requires_approval ? Auth::id() : null);
        }, 3);
    }

    public function reject(StockAdjustment $adj, ?string $reason): StockAdjustment
    {
        return DB::transaction(function () use ($adj, $reason): StockAdjustment {
            $adj = StockAdjustment::query()->whereKey($adj->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($adj, ['pending_approval']);
            $adj->update(['status' => 'rejected']);
            $this->audit->log('reject', 'stock_adjustments', $adj->id, ['status' => 'pending_approval'], ['status' => 'rejected'], $reason, Auth::id());

            return $this->fresh($adj);
        });
    }

    public function delete(StockAdjustment $adj): void
    {
        $this->assertStatus($adj, ['draft', 'rejected']);
        $adj->items()->delete();
        $adj->delete();
    }

    private function apply(StockAdjustment $adj, ?int $approver): StockAdjustment
    {
        $this->period->assertOpen(Branches::today());
        $items = $adj->items()->orderBy('id')->get();
        if ($items->isEmpty()) {
            throw new ApiException('EMPTY_RECEIPT', 422);
        }
        $reason = LookupValue::query()->findOrFail($adj->reason_id);
        $lines = [];
        $total = 0;
        foreach ($items as $item) {
            $qty = Dec::parse((string) $item->qty_delta, Dec::QTY);
            $type = $this->movementType($qty, (bool) $item->is_breakage, $reason->code);
            $cost = $qty > 0 && (float) $item->unit_cost > 0 ? (string) $item->unit_cost : null;
            $lines[] = ['variant_id' => $item->variant_id, 'warehouse_id' => $adj->warehouse_id, 'type' => $type, 'qty' => Dec::fmt($qty, Dec::QTY), 'unit_cost' => $cost,
                'ref_table' => 'stock_adjustments', 'ref_id' => $adj->id, 'ref_no' => $adj->adj_no, 'reason' => $reason->label_en, 'note' => $item->note, 'occurred_at' => now()];
        }
        $movements = $this->stock->postMany($lines);
        foreach ($items as $i => $item) {
            $cost = Dec::parse((string) $movements[$i]->unit_cost, Dec::COST);
            $item->update(['unit_cost' => Dec::fmt($cost, Dec::COST)]);
            $total += Dec::value(Dec::parse((string) $item->qty_delta, Dec::QTY), $cost);
        }
        $adj->update(['status' => 'posted', 'posted_at' => now(), 'approved_by' => $approver, 'approved_at' => $approver ? now() : null, 'total_value' => Dec::fmt($total, Dec::MONEY)]);
        $this->audit->log('stock_adjust', 'stock_adjustments', $adj->id, ['status' => 'draft'], ['status' => 'posted', 'adj_no' => $adj->adj_no, 'reason' => $reason->label_en, 'value' => Dec::fmt($total, Dec::MONEY)], null, $approver);

        return $this->fresh($adj);
    }

    /** Breakage lines and the "damaged in transit" reason have their own movement types so the breakage register can find them. */
    private function movementType(int $qty, bool $breakage, string $reasonCode): string
    {
        if ($qty > 0) {
            return 'adjustment_in';
        }
        if ($breakage || $reasonCode === 'breakage') {
            return 'breakage';
        }

        return $reasonCode === 'damaged_in_transit' ? 'damage' : 'adjustment_out';
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function writeItems(StockAdjustment $adj, array $items): void
    {
        $adj->items()->forceDelete();
        $abs = 0;
        $value = 0;
        $absValue = 0;
        $reason = LookupValue::query()->findOrFail($adj->reason_id);
        foreach ($items as $row) {
            $variant = ProductVariant::query()->findOrFail((int) $row['variant_id']);
            if ($variant->is_bundle) {
                throw new ApiException('BUNDLE_NO_STOCK', 422, '', [], ['sku' => $variant->sku]);
            }
            $qty = Dec::parse((string) $row['qty_delta'], Dec::QTY);
            if ($qty === 0) {
                throw new ApiException('VALIDATION', 422, '', ['items' => ['errors.REQUIRED']]);
            }
            $breakage = (bool) ($row['is_breakage'] ?? false) || ($qty < 0 && $reason->code === 'breakage');
            $level = StockLevel::query()->where(['variant_id' => $variant->id, 'warehouse_id' => $adj->warehouse_id])->first();
            $cost = isset($row['unit_cost']) && $row['unit_cost'] !== null && $row['unit_cost'] !== ''
                ? Dec::parse((string) $row['unit_cost'], Dec::COST)
                : ($level ? Dec::parse((string) $level->avg_cost, Dec::COST) : 0);
            if ($cost === 0) {
                $cost = Dec::parse((string) $variant->last_cost, Dec::COST);
            }
            if (isset($row['photo_attachment_id']) && $row['photo_attachment_id'] !== null) {
                $owns = DB::table('attachments')->where(['id' => $row['photo_attachment_id'], 'entity_table' => 'stock_adjustments', 'entity_id' => $adj->id])->exists();
                if (! $owns) {
                    throw new ApiException('VALIDATION', 422, '', ['items' => ['errors.ATTACHMENT_MISMATCH']]);
                }
            }
            StockAdjustmentItem::query()->create([
                'adjustment_id' => $adj->id, 'variant_id' => $variant->id, 'qty_delta' => Dec::fmt($qty, Dec::QTY), 'unit_cost' => Dec::fmt($cost, Dec::COST),
                'is_breakage' => $breakage, 'supplier_id' => $row['supplier_id'] ?? null, 'photo_attachment_id' => $row['photo_attachment_id'] ?? null, 'note' => $row['note'] ?? null,
            ]);
            $abs += abs($qty);
            $value += Dec::value($qty, $cost);
            $absValue += abs(Dec::value($qty, $cost));
        }
        $needs = $abs > Dec::parse((string) $this->settings->get('adjustment_approval_threshold_qty'), Dec::QTY)
            || $absValue > Dec::parse((string) $this->settings->get('adjustment_approval_threshold_value'), Dec::MONEY);
        $adj->update(['total_value' => Dec::fmt($value, Dec::MONEY), 'requires_approval' => $needs]);
    }

    private function assertReason(int $id): void
    {
        $ok = LookupValue::query()->whereKey($id)->whereHas('type', fn ($t) => $t->where('key', 'adjustment_reason'))->where('is_active', true)->exists();
        if (! $ok) {
            throw new ApiException('VALIDATION', 422, '', ['reason_id' => ['errors.REQUIRED']]);
        }
    }

    /** @param  array<int, string>  $from */
    private function assertStatus(StockAdjustment $adj, array $from): void
    {
        if (! in_array($adj->status, $from, true)) {
            throw new ApiException('INVALID_STATE', 409, '', [], ['status' => $adj->status]);
        }
    }

    private function fresh(StockAdjustment $adj): StockAdjustment
    {
        return $adj->refresh()->load(['warehouse', 'reason', 'items.variant']);
    }
}
