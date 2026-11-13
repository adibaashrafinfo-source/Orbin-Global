<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-408: what is at or below its reorder level, grouped by the supplier it was last bought from, and one click to PO drafts. */
class ReorderService
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    /** @return array<int, array<string, mixed>> */
    public function suggestions(?int $warehouseId, bool $canCost): array
    {
        $stock = DB::table('stock_levels')->whereNull('deleted_at')->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->groupBy('variant_id')->selectRaw('variant_id, SUM(qty_on_hand) as on_hand');
        $rows = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->leftJoinSub($stock, 's', 's.variant_id', '=', 'v.id')
            ->whereNull('v.deleted_at')->whereNull('p.deleted_at')->where('v.is_active', true)->where('v.is_bundle', false)->where('v.is_discontinued', false)->where('v.reorder_level', '>', 0)
            ->whereRaw('COALESCE(s.on_hand, 0) <= v.reorder_level')
            ->selectRaw('v.id as variant_id, v.sku, v.variant_name, v.reorder_level, v.reorder_qty, v.last_cost, p.name_en as product_name, COALESCE(s.on_hand, 0) as on_hand')
            ->orderBy('p.name_en')->orderBy('v.id')->get();
        $out = [];
        foreach ($rows as $r) {
            $supplier = DB::table('goods_receipt_items as i')->join('goods_receipts as g', 'g.id', '=', 'i.grn_id')->join('suppliers as s', 's.id', '=', 'g.supplier_id')
                ->where('i.variant_id', $r->variant_id)->where('g.status', 'posted')->whereNull('g.deleted_at')->orderByDesc('g.received_date')->orderByDesc('g.id')->first(['s.id', 's.name']);
            $onHand = Dec::parse((string) $r->on_hand, Dec::QTY);
            $level = Dec::parse((string) $r->reorder_level, Dec::QTY);
            $qty = Dec::parse((string) $r->reorder_qty, Dec::QTY);
            $suggested = $qty > 0 ? $qty : max($level - $onHand, 1000);
            $row = ['variant_id' => (int) $r->variant_id, 'sku' => $r->sku, 'variant_name' => $r->variant_name, 'product_name' => $r->product_name, 'on_hand' => Dec::fmt($onHand, Dec::QTY),
                'reorder_level' => Dec::fmt($level, Dec::QTY), 'suggested_qty' => Dec::fmt($suggested, Dec::QTY), 'supplier_id' => $supplier?->id, 'supplier' => $supplier?->name];
            if ($canCost) {
                $row['last_cost'] = $r->last_cost;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Draft one purchase order per supplier from the chosen lines. Lines without a supplier cannot be ordered.
     *
     * @param  array<int, array{variant_id: int, qty: string, supplier_id: int}>  $lines
     * @return array<int, PurchaseOrder>
     */
    public function createDrafts(array $lines): array
    {
        $groups = [];
        foreach ($lines as $l) {
            $variant = ProductVariant::query()->findOrFail((int) $l['variant_id']);
            $groups[(int) $l['supplier_id']][] = ['variant_id' => $variant->id, 'qty_ordered' => $l['qty'], 'unit_cost' => (string) $variant->last_cost];
        }
        if ($groups === []) {
            throw new ApiException('VALIDATION', 422, '', ['lines' => ['errors.REQUIRED']]);
        }

        return DB::transaction(function () use ($groups): array {
            $made = [];
            foreach ($groups as $supplierId => $items) {
                $made[] = $this->orders->create(['supplier_id' => $supplierId, 'order_date' => Branches::today(), 'note' => 'Created from reorder suggestions', 'items' => $items]);
            }

            return $made;
        }, 3);
    }
}
