<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\SettingsService;
use App\Support\Dec;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The only code that changes stock (CLAUDE.md rule 6). Every call must run inside the caller's DB transaction:
 * it locks the stock_levels rows in a fixed order, checks for negative stock, writes the append-only movement and
 * updates stock_levels in the same transaction. Costs are weighted average (BR-032).
 */
class StockPostingService
{
    /** Movement types that blend their cost into the weighted average. Everything else leaves the average alone. */
    public const COST_SETTING = ['opening', 'purchase_receipt', 'transfer_in'];

    /**
     * Units that arrive broken are booked in and straight out again, so the damage shows in the ledger and the breakage
     * register. The incoming half carries this reason and must not move the average: only the good units set the cost.
     */
    public const ARRIVAL_DAMAGE = 'damaged_on_arrival';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Post several lines at once. Rows are locked in (variant, warehouse) order first, so two documents that touch the
     * same variants can never deadlock each other on stock rows.
     *
     * @param  array<int, array{variant_id: int, warehouse_id: int, type: string, qty: string, unit_cost?: string|null, ref_table?: string|null, ref_id?: int|null, ref_no?: string|null, reason?: string|null, note?: string|null, occurred_at?: CarbonInterface|null}>  $lines
     * @return array<int, StockMovement>
     */
    public function postMany(array $lines): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Stock may only be posted inside a database transaction.');
        }
        $this->lockLevels($lines);
        $out = [];
        foreach ($lines as $i => $line) {
            $out[$i] = $this->postLocked($line);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $line */
    public function post(array $line): StockMovement
    {
        return $this->postMany([$line])[0];
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function lockLevels(array $lines): void
    {
        $pairs = [];
        foreach ($lines as $line) {
            $pairs[$line['variant_id'].'-'.$line['warehouse_id']] = [(int) $line['variant_id'], (int) $line['warehouse_id']];
        }
        uasort($pairs, fn ($a, $b) => $a <=> $b);
        // Lock order is always variant first, then stock rows. Inserting a movement takes a shared lock on its variant (foreign
        // key), and a goods receipt updates the variant's last cost, so taking the variant up front keeps those two from deadlocking.
        DB::table('product_variants')->whereIn('id', array_values(array_unique(array_column($pairs, 0))))->orderBy('id')->sharedLock()->pluck('id');
        foreach ($pairs as [$variantId, $warehouseId]) {
            // One statement creates a missing row or takes the exclusive lock on the existing one. INSERT IGNORE would take a
            // shared lock on a duplicate and then fight other sessions to upgrade it, which deadlocks under load.
            DB::statement(
                'INSERT INTO stock_levels (variant_id, warehouse_id, qty_on_hand, qty_reserved, avg_cost, created_at, updated_at) VALUES (?, ?, 0, 0, 0, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)) ON DUPLICATE KEY UPDATE id = id',
                [$variantId, $warehouseId],
            );
            DB::table('stock_levels')->where(['variant_id' => $variantId, 'warehouse_id' => $warehouseId])->lockForUpdate()->first();
        }
    }

    /** @param  array<string, mixed>  $line */
    private function postLocked(array $line): StockMovement
    {
        $type = (string) $line['type'];
        if (! in_array($type, StockMovement::TYPES, true)) {
            throw new LogicException("Unknown movement type {$type}");
        }
        $delta = Dec::parse((string) $line['qty'], Dec::QTY);
        if ($delta === 0) {
            throw new ApiException('VALIDATION', 422, '', ['qty' => ['errors.REQUIRED']]);
        }
        $variantId = (int) $line['variant_id'];
        $warehouseId = (int) $line['warehouse_id'];

        $variant = ProductVariant::query()->findOrFail($variantId);
        if ($variant->is_bundle) {
            throw new ApiException('BUNDLE_NO_STOCK', 422, '', [], ['variant_id' => $variantId, 'sku' => $variant->sku]);
        }
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        /** @var StockLevel $level */
        // A locking read, never a plain one: under REPEATABLE READ a plain read inside a long transaction returns the snapshot taken
        // earlier, which can predate another session's committed posting and would silently lose it.
        $level = StockLevel::query()->where(['variant_id' => $variantId, 'warehouse_id' => $warehouseId])->lockForUpdate()->firstOrFail();
        $onHand = Dec::parse((string) $level->qty_on_hand, Dec::QTY);
        $avg = Dec::parse((string) $level->avg_cost, Dec::COST);
        $after = $onHand + $delta;

        if ($after < 0 && ! $this->settings->get('allow_negative_stock')) {
            // The transaction rolls back, so nothing persistent can be written here; the log line is the record (FR-407).
            Log::warning('Negative stock blocked', ['variant_id' => $variantId, 'warehouse_id' => $warehouseId, 'on_hand' => Dec::fmt($onHand, Dec::QTY), 'requested' => Dec::fmt($delta, Dec::QTY)]);
            throw new ApiException('INSUFFICIENT_STOCK', 422, '', [], [
                'variant_id' => $variantId, 'sku' => $variant->sku, 'warehouse_id' => $warehouseId,
                'available' => Dec::fmt(max($onHand, 0), Dec::QTY), 'requested' => Dec::fmt(-$delta, Dec::QTY),
            ]);
        }

        $keepAvg = $type === 'purchase_receipt' && ($line['reason'] ?? null) === self::ARRIVAL_DAMAGE;
        [$cost, $newAvg] = $this->costing($type, $delta, $onHand, $avg, isset($line['unit_cost']) ? Dec::parse((string) $line['unit_cost'], Dec::COST) : null, $variant, $keepAvg);
        $when = $line['occurred_at'] ?? now();

        $movement = StockMovement::query()->create([
            'branch_id' => $warehouse->branch_id, 'variant_id' => $variantId, 'warehouse_id' => $warehouseId, 'movement_type' => $type,
            'qty_delta' => Dec::fmt($delta, Dec::QTY), 'unit_cost' => Dec::fmt($cost, Dec::COST), 'balance_after' => Dec::fmt($after, Dec::QTY),
            'ref_table' => $line['ref_table'] ?? null, 'ref_id' => $line['ref_id'] ?? null, 'ref_no' => $line['ref_no'] ?? null,
            'reason' => $line['reason'] ?? null, 'note' => $line['note'] ?? null, 'occurred_at' => $when,
        ]);
        $level->forceFill(['qty_on_hand' => Dec::fmt($after, Dec::QTY), 'avg_cost' => Dec::fmt($newAvg, Dec::COST), 'last_movement_at' => $when])->save();

        return $movement;
    }

    /**
     * The one place the costing rules live. Also used to replay history for as-of valuation and the integrity check,
     * so live stock and replayed stock cannot disagree.
     *
     * @return array{0: int, 1: int} [movement unit cost at scale 4, new average at scale 4]
     */
    public static function costRule(string $type, int $delta, int $onHand, int $avg, ?int $givenCost, int $fallbackCost = 0, bool $keepAvg = false): array
    {
        if ($delta < 0) {
            return [$givenCost ?? $avg, $avg];
        }
        $cost = $givenCost ?? ($avg > 0 ? $avg : $fallbackCost);
        if (! $keepAvg && in_array($type, self::COST_SETTING, true)) {
            $newAvg = $onHand <= 0
                ? $cost
                : Dec::div(Dec::mul($onHand, $avg) + Dec::mul($delta, $cost), $onHand + $delta);

            return [$cost, $newAvg];
        }

        // Other incoming movements keep the average, except that an empty shelf with no cost yet adopts the incoming cost.
        return [$cost, $onHand <= 0 && $avg === 0 ? $cost : $avg];
    }

    /** @return array{0: int, 1: int} */
    private function costing(string $type, int $delta, int $onHand, int $avg, ?int $givenCost, ProductVariant $variant, bool $keepAvg): array
    {
        $fallback = $variant->last_cost !== null ? Dec::parse((string) $variant->last_cost, Dec::COST) : 0;

        return self::costRule($type, $delta, $onHand, $avg, $givenCost, $fallback, $keepAvg);
    }
}
