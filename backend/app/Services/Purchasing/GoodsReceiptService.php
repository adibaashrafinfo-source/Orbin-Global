<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\ProductVariant;
use App\Models\PurchaseExtraCost;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Services\Inventory\StockPostingService;
use App\Services\NumberService;
use App\Support\Dec;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-503. A goods receipt brings stock in, spreads the extra costs (freight, duty, …) over the lines to get a landed
 * unit cost, moves the weighted average, and credits the supplier. Posting is one transaction and happens once.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly NumberService $numbers,
        private readonly AuditService $audit,
        private readonly PeriodLockService $period,
        private readonly StockPostingService $stock,
        private readonly SupplierService $suppliers,
        private readonly PurchaseOrderService $orders,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data): GoodsReceipt {
            $this->period->assertOpen($data['received_date']);
            $warehouse = Warehouse::query()->findOrFail((int) $data['warehouse_id']);
            $po = isset($data['po_id']) ? $this->usableOrder((int) $data['po_id'], (int) $data['supplier_id']) : null;
            Supplier::query()->findOrFail((int) $data['supplier_id']);
            $grn = GoodsReceipt::query()->create([
                'branch_id' => $warehouse->branch_id, 'grn_no' => $this->numbers->next('goods_receipt', $warehouse->branch_id, Carbon::parse($data['received_date'])),
                'warehouse_id' => $warehouse->id, 'supplier_id' => $data['supplier_id'], 'po_id' => $po?->id,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null, 'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
                'received_date' => $data['received_date'], 'status' => 'draft', 'allocation_basis' => $data['allocation_basis'] ?? 'value', 'note' => $data['note'] ?? null,
            ]);
            $this->writeLines($grn, $data['items'] ?? [], $data['extra_costs'] ?? []);

            return $this->fresh($grn);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function update(GoodsReceipt $grn, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($grn, $data): GoodsReceipt {
            $grn = GoodsReceipt::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($grn);
            if (isset($data['received_date'])) {
                $this->period->assertOpen($data['received_date']);
            }
            $grn->update(array_intersect_key($data, array_flip(['supplier_invoice_no', 'supplier_invoice_date', 'received_date', 'allocation_basis', 'note'])));
            if (isset($data['items']) || isset($data['extra_costs'])) {
                $this->writeLines(
                    $grn,
                    $data['items'] ?? $grn->items->map(fn ($i) => ['po_item_id' => $i->po_item_id, 'variant_id' => $i->variant_id, 'qty_received' => $i->qty_received, 'qty_damaged' => $i->qty_damaged, 'unit_cost' => $i->unit_cost])->all(),
                    $data['extra_costs'] ?? $grn->extraCosts->map(fn ($e) => $e->only(['cost_type', 'description', 'amount', 'paid_to', 'account_id']))->all(),
                );
            }

            return $this->fresh($grn);
        }, 3);
    }

    public function cancel(GoodsReceipt $grn): GoodsReceipt
    {
        return DB::transaction(function () use ($grn): GoodsReceipt {
            $grn = GoodsReceipt::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($grn);
            $grn->update(['status' => 'cancelled']);
            $this->audit->log('cancel', 'goods_receipts', $grn->id, ['status' => 'draft'], ['status' => 'cancelled']);

            return $this->fresh($grn);
        });
    }

    public function delete(GoodsReceipt $grn): void
    {
        $this->assertDraft($grn);
        $grn->items()->delete();
        $grn->extraCosts()->delete();
        $grn->delete();
    }

    /**
     * What posting would do, without doing it: per-line allocation and landed cost. Drives the live panel in the editor
     * and is the same arithmetic as post(), so the screen and the books cannot differ.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $extras
     * @return array{lines: array<int, array<string, mixed>>, items_total: string, extra_cost_total: string, grand_total: string, billed_extras: string}
     */
    public function preview(array $items, array $extras, string $basis): array
    {
        $calc = $this->calculate($items, $extras, $basis, false);
        $lines = [];
        foreach ($calc['lines'] as $i => $l) {
            $lines[$i] = [
                'variant_id' => $l['variant_id'], 'qty_received' => Dec::fmt($l['received'], Dec::QTY), 'qty_damaged' => Dec::fmt($l['damaged'], Dec::QTY),
                'qty_good' => Dec::fmt($l['good'], Dec::QTY), 'unit_cost' => Dec::fmt($l['cost2'], Dec::MONEY), 'line_total' => Dec::fmt($l['line'], Dec::MONEY),
                'allocated_extra' => Dec::fmt($l['allocated'], Dec::MONEY), 'landed_unit_cost' => Dec::fmt($l['landed4'], Dec::COST),
            ];
        }

        return [
            'lines' => $lines, 'items_total' => Dec::fmt($calc['items_total'], Dec::MONEY), 'extra_cost_total' => Dec::fmt($calc['extra_total'], Dec::MONEY),
            'grand_total' => Dec::fmt($calc['items_total'] + $calc['extra_total'], Dec::MONEY), 'billed_extras' => Dec::fmt($calc['billed_extras'], Dec::MONEY),
        ];
    }

    public function post(GoodsReceipt $grn): GoodsReceipt
    {
        return DB::transaction(function () use ($grn): GoodsReceipt {
            $g = GoodsReceipt::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($g);
            $this->period->assertOpen($g->received_date);
            $items = $g->items()->orderBy('id')->get();
            $extras = $g->extraCosts()->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw new ApiException('EMPTY_RECEIPT', 422);
            }
            $calc = $this->calculate(
                $items->map(fn ($i) => ['variant_id' => $i->variant_id, 'qty_received' => $i->qty_received, 'qty_damaged' => $i->qty_damaged, 'unit_cost' => $i->unit_cost])->all(),
                $extras->map(fn ($e) => $e->only(['amount', 'paid_to']))->all(),
                $g->allocation_basis,
            );
            if (array_sum(array_column($calc['lines'], 'received')) === 0) {
                throw new ApiException('EMPTY_RECEIPT', 422);
            }

            $po = $g->po_id ? PurchaseOrder::query()->whereKey($g->po_id)->lockForUpdate()->firstOrFail() : null;
            if ($po) {
                $this->assertOrderReceivable($po, $items, $calc);
            }

            // Movements: good units enter at landed cost; damaged units are booked in at the average (no cost effect) and out as damage.
            $lines = [];
            foreach ($calc['lines'] as $i => $l) {
                $common = ['variant_id' => $l['variant_id'], 'warehouse_id' => $g->warehouse_id, 'ref_table' => 'goods_receipts', 'ref_id' => $g->id, 'ref_no' => $g->grn_no, 'occurred_at' => now()];
                if ($l['good'] > 0) {
                    $lines[] = $common + ['type' => 'purchase_receipt', 'qty' => Dec::fmt($l['good'], Dec::QTY), 'unit_cost' => Dec::fmt($l['landed4'], Dec::COST)];
                }
                if ($l['damaged'] > 0) {
                    $lines[] = $common + ['type' => 'purchase_receipt', 'qty' => Dec::fmt($l['damaged'], Dec::QTY), 'unit_cost' => Dec::fmt($l['landed4'], Dec::COST), 'reason' => StockPostingService::ARRIVAL_DAMAGE];
                    $lines[] = $common + ['type' => 'damage', 'qty' => Dec::fmt(-$l['damaged'], Dec::QTY), 'unit_cost' => Dec::fmt($l['landed4'], Dec::COST), 'reason' => StockPostingService::ARRIVAL_DAMAGE];
                }
            }
            $this->stock->postMany($lines);

            foreach ($items as $i => $item) {
                $l = $calc['lines'][$i];
                $item->update(['allocated_extra' => Dec::fmt($l['allocated'], Dec::MONEY), 'landed_unit_cost' => Dec::fmt($l['landed4'], Dec::COST), 'line_total' => Dec::fmt($l['line'], Dec::MONEY)]);
                if ($l['good'] > 0) {
                    ProductVariant::query()->whereKey($l['variant_id'])->update(['last_cost' => Dec::fmt(Dec::rescale($l['landed4'], Dec::COST, Dec::MONEY), Dec::MONEY)]);
                }
                if ($po && $item->po_item_id) {
                    PurchaseOrderItem::query()->whereKey($item->po_item_id)->update(['qty_received' => DB::raw('qty_received + '.Dec::fmt($l['received'], Dec::QTY))]);
                }
            }
            if ($po) {
                $this->orders->refreshStatusAfterReceipt($po->refresh());
            }

            $g->update([
                'items_total' => Dec::fmt($calc['items_total'], Dec::MONEY), 'extra_cost_total' => Dec::fmt($calc['extra_total'], Dec::MONEY),
                'grand_total' => Dec::fmt($calc['items_total'] + $calc['extra_total'], Dec::MONEY), 'status' => 'posted', 'posted_at' => now(),
            ]);

            // The supplier is owed the goods plus any extras they billed. Extras paid to someone else are not their debt.
            $owed = $calc['items_total'] + $calc['billed_extras'];
            if ($owed > 0) {
                $this->suppliers->ledger($g->supplier, 'invoice', 0, $owed, 'goods_receipts', $g->id, 'Goods receipt '.$g->grn_no, $g->grn_no, $g->received_date->toDateString(), $g->branch_id);
            }
            $this->audit->log('post', 'goods_receipts', $g->id, ['status' => 'draft'], [
                'status' => 'posted', 'grn_no' => $g->grn_no, 'items_total' => Dec::fmt($calc['items_total'], Dec::MONEY), 'extra_cost_total' => Dec::fmt($calc['extra_total'], Dec::MONEY), 'supplier_credit' => Dec::fmt($owed, Dec::MONEY),
            ]);

            return $this->fresh($g);
        }, 3);
    }

    /**
     * The landed cost arithmetic. Extras are split over lines that have good units, by line value or by quantity, to the
     * paisa; landed unit cost = (good qty × unit cost + allocated extra) ÷ good qty at 4 decimals (FR-503).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $extras
     * @return array{lines: array<int, array<string, int>>, items_total: int, extra_total: int, billed_extras: int}
     */
    private function calculate(array $items, array $extras, string $basis, bool $strict = true): array
    {
        $lines = [];
        $itemsTotal = 0;
        foreach ($items as $i => $row) {
            $received = Dec::parse((string) $row['qty_received'], Dec::QTY);
            $damaged = Dec::parse((string) ($row['qty_damaged'] ?? '0'), Dec::QTY);
            if ($received < 0 || $damaged < 0 || $damaged > $received) {
                throw new ApiException('VALIDATION', 422, '', ["items.{$i}.qty_damaged" => ['errors.DAMAGED_EXCEEDS_RECEIVED']]);
            }
            $cost2 = Dec::parse((string) $row['unit_cost'], Dec::MONEY);
            $line = Dec::value($received, Dec::rescale($cost2, Dec::MONEY, Dec::COST));
            $itemsTotal += $line;
            $lines[$i] = ['variant_id' => (int) $row['variant_id'], 'received' => $received, 'damaged' => $damaged, 'good' => $received - $damaged, 'cost2' => $cost2, 'line' => $line, 'allocated' => 0, 'landed4' => 0];
        }

        $extraTotal = 0;
        $billed = 0;
        foreach ($extras as $e) {
            $amount = Dec::parse((string) $e['amount'], Dec::MONEY);
            $extraTotal += $amount;
            if (trim((string) ($e['paid_to'] ?? '')) === '') {
                $billed += $amount;
            }
        }

        $weights = [];
        foreach ($lines as $i => $l) {
            $weights[$i] = $l['good'] > 0 ? ($basis === 'quantity' ? $l['received'] : $l['line']) : 0;
        }
        if (array_sum($weights) === 0 && $extraTotal > 0) {
            // Value basis on free goods has nothing to weigh by; fall back to quantity so the extras still land somewhere.
            foreach ($lines as $i => $l) {
                $weights[$i] = $l['good'] > 0 ? $l['received'] : 0;
            }
        }
        if ($strict && $extraTotal > 0 && array_sum($weights) === 0) {
            throw new ApiException('EMPTY_RECEIPT', 422, '', [], ['reason' => 'no_good_units_for_extra_costs']);
        }
        $alloc = Dec::allocate($extraTotal, $weights);

        foreach ($lines as $i => &$l) {
            $l['allocated'] = $alloc[$i];
            if ($l['good'] > 0) {
                $goodValue7 = Dec::mul($l['good'], Dec::rescale($l['cost2'], Dec::MONEY, Dec::COST));
                $l['landed4'] = Dec::div($goodValue7 + Dec::mul($l['allocated'], 100000), $l['good']);
            } else {
                $l['landed4'] = Dec::rescale($l['cost2'], Dec::MONEY, Dec::COST);
            }
        }
        unset($l);

        return ['lines' => $lines, 'items_total' => $itemsTotal, 'extra_total' => $extraTotal, 'billed_extras' => $billed];
    }

    /** @param  array<int, array<string, mixed>>  $items  @param  array<int, array<string, mixed>>  $extras */
    private function writeLines(GoodsReceipt $grn, array $items, array $extras): void
    {
        $items = array_values($items);
        $po = $grn->po_id ? PurchaseOrder::query()->with('items')->find($grn->po_id) : null;
        $grn->items()->forceDelete();
        $grn->extraCosts()->forceDelete();
        foreach ($items as $row) {
            $variant = ProductVariant::query()->findOrFail((int) $row['variant_id']);
            if ($variant->is_bundle) {
                throw new ApiException('BUNDLE_NO_STOCK', 422, '', [], ['sku' => $variant->sku]);
            }
            $poItemId = $row['po_item_id'] ?? null;
            if ($poItemId !== null && (! $po || ! $po->items->contains('id', (int) $poItemId))) {
                throw new ApiException('VALIDATION', 422, '', ['items' => ['errors.PO_ITEM_MISMATCH']]);
            }
        }
        $calc = $this->calculate($items, $extras, $grn->allocation_basis, false);
        foreach ($items as $i => $row) {
            $l = $calc['lines'][$i];
            GoodsReceiptItem::query()->create([
                'grn_id' => $grn->id, 'po_item_id' => $row['po_item_id'] ?? null, 'variant_id' => $l['variant_id'],
                'qty_received' => Dec::fmt($l['received'], Dec::QTY), 'qty_damaged' => Dec::fmt($l['damaged'], Dec::QTY),
                'unit_cost' => Dec::fmt($l['cost2'], Dec::MONEY), 'line_total' => Dec::fmt($l['line'], Dec::MONEY),
            ]);
        }
        foreach ($extras as $e) {
            PurchaseExtraCost::query()->create([
                'grn_id' => $grn->id, 'cost_type' => $e['cost_type'], 'description' => $e['description'] ?? null,
                'amount' => Dec::fmt(Dec::parse((string) $e['amount'], Dec::MONEY), Dec::MONEY), 'paid_to' => ($e['paid_to'] ?? '') !== '' ? $e['paid_to'] : null, 'account_id' => $e['account_id'] ?? null,
            ]);
        }
        $grn->update([
            'items_total' => Dec::fmt($calc['items_total'], Dec::MONEY), 'extra_cost_total' => Dec::fmt($calc['extra_total'], Dec::MONEY),
            'grand_total' => Dec::fmt($calc['items_total'] + $calc['extra_total'], Dec::MONEY),
        ]);
    }

    private function usableOrder(int $poId, int $supplierId): PurchaseOrder
    {
        $po = PurchaseOrder::query()->findOrFail($poId);
        if (! in_array($po->status, ['approved', 'partially_received'], true)) {
            throw new ApiException('INVALID_STATE', 409, '', [], ['status' => $po->status]);
        }
        if ($po->supplier_id !== $supplierId) {
            throw new ApiException('VALIDATION', 422, '', ['supplier_id' => ['errors.PO_SUPPLIER_MISMATCH']]);
        }

        return $po;
    }

    /** Receiving more than was ordered is refused (OVER_RECEIPT); the owner can raise the order instead. */
    private function assertOrderReceivable(PurchaseOrder $po, $items, array $calc): void
    {
        if (! in_array($po->status, ['approved', 'partially_received'], true)) {
            throw new ApiException('INVALID_STATE', 409, '', [], ['status' => $po->status]);
        }
        $incoming = [];
        foreach ($items as $i => $item) {
            if ($item->po_item_id) {
                $incoming[$item->po_item_id] = ($incoming[$item->po_item_id] ?? 0) + $calc['lines'][$i]['received'];
            }
        }
        foreach ($po->items()->whereIn('id', array_keys($incoming))->lockForUpdate()->get() as $poItem) {
            $total = Dec::parse((string) $poItem->qty_received, Dec::QTY) + $incoming[$poItem->id];
            if ($total > Dec::parse((string) $poItem->qty_ordered, Dec::QTY)) {
                throw new ApiException('OVER_RECEIPT', 422, '', [], ['variant_id' => $poItem->variant_id, 'ordered' => $poItem->qty_ordered, 'already_received' => $poItem->qty_received]);
            }
        }
    }

    private function assertDraft(GoodsReceipt $grn): void
    {
        if ($grn->status !== 'draft') {
            throw new ApiException('INVALID_STATE', 409, '', [], ['status' => $grn->status]);
        }
    }

    private function fresh(GoodsReceipt $grn): GoodsReceipt
    {
        return $grn->refresh()->load(['supplier', 'warehouse', 'items.variant', 'extraCosts']);
    }
}
