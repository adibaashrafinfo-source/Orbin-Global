<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Services\NumberService;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** FR-502 and FR-507. draft → approved → partially_received → received → closed, or cancelled. */
class PurchaseOrderService
{
    public function __construct(
        private readonly NumberService $numbers,
        private readonly AuditService $audit,
        private readonly PeriodLockService $period,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $this->period->assertOpen($data['order_date']);
            $this->activeSupplier((int) $data['supplier_id']);
            $branch = Branches::currentId();
            $po = PurchaseOrder::query()->create([
                'branch_id' => $branch, 'po_no' => $this->numbers->next('purchase_order', $branch, Carbon::parse($data['order_date'])),
                'supplier_id' => $data['supplier_id'], 'status' => 'draft', 'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null, 'note' => $data['note'] ?? null,
            ]);
            $this->writeItems($po, $data['items'], (string) ($data['extra_total'] ?? '0'));

            return $this->fresh($po);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data): PurchaseOrder {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($po, ['draft']);
            if (isset($data['supplier_id'])) {
                $this->activeSupplier((int) $data['supplier_id']);
            }
            $po->update(array_intersect_key($data, array_flip(['supplier_id', 'order_date', 'expected_date', 'note'])));
            if (isset($data['items'])) {
                $this->writeItems($po, $data['items'], (string) ($data['extra_total'] ?? $po->extra_total));
            } elseif (isset($data['extra_total'])) {
                $this->writeItems($po, $po->items->map(fn ($i) => ['variant_id' => $i->variant_id, 'qty_ordered' => $i->qty_ordered, 'unit_cost' => $i->unit_cost, 'tax_rate_percent' => $i->tax_rate_percent])->all(), (string) $data['extra_total']);
            }

            return $this->fresh($po);
        }, 3);
    }

    public function approve(PurchaseOrder $po): PurchaseOrder
    {
        return $this->transition($po, ['draft'], 'approved', 'approve', function (PurchaseOrder $po): void {
            if ($po->items()->count() === 0) {
                throw new ApiException('EMPTY_ORDER', 422);
            }
            $po->approved_by = Auth::id();
            $po->approved_at = now();
        });
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        return $this->transition($po, ['draft', 'approved'], 'cancelled', 'cancel', function (PurchaseOrder $po): void {
            if ($po->items()->where('qty_received', '>', 0)->exists()) {
                throw new ApiException('INVALID_STATE', 409);
            }
        });
    }

    /** Stop waiting for the rest of a part-delivered order. */
    public function close(PurchaseOrder $po): PurchaseOrder
    {
        return $this->transition($po, ['partially_received', 'received'], 'closed', 'close');
    }

    public function delete(PurchaseOrder $po): void
    {
        $this->assertStatus($po, ['draft', 'cancelled']);
        $po->items()->delete();
        $po->delete();
    }

    /** Last five purchase costs of a variant, newest first, with the supplier, for the PO line hint (FR-507). @return array<int, array<string, mixed>> */
    public function priceHistory(int $variantId, ?int $supplierId = null): array
    {
        return DB::table('goods_receipt_items as i')
            ->join('goods_receipts as g', 'g.id', '=', 'i.grn_id')->join('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->where('i.variant_id', $variantId)->where('g.status', 'posted')->whereNull('i.deleted_at')->whereNull('g.deleted_at')
            ->when($supplierId, fn ($q) => $q->where('g.supplier_id', $supplierId))
            ->orderByDesc('g.received_date')->orderByDesc('g.id')->limit(5)
            ->get(['g.received_date as date', 'g.grn_no', 'g.supplier_id', 's.name as supplier', 'i.unit_cost', 'i.landed_unit_cost', 'i.qty_received'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Recompute received quantities' effect on the status after a goods receipt posts. */
    public function refreshStatusAfterReceipt(PurchaseOrder $po): void
    {
        $open = $po->items()->whereColumn('qty_received', '<', 'qty_ordered')->exists();
        $po->update(['status' => $open ? 'partially_received' : 'received']);
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function writeItems(PurchaseOrder $po, array $items, string $extraTotal): void
    {
        $po->items()->forceDelete();
        $subtotal = 0;
        $tax = 0;
        foreach ($items as $row) {
            $variant = ProductVariant::query()->with('product')->findOrFail((int) $row['variant_id']);
            if ($variant->is_bundle) {
                throw new ApiException('BUNDLE_NO_STOCK', 422, '', [], ['sku' => $variant->sku]);
            }
            $qty = Dec::parse((string) $row['qty_ordered'], Dec::QTY);
            $cost = Dec::parse((string) $row['unit_cost'], Dec::MONEY);
            $rate = isset($row['tax_rate_percent']) && $row['tax_rate_percent'] !== null ? Dec::parse((string) $row['tax_rate_percent'], Dec::MONEY) : $this->defaultRate($variant);
            $line = Dec::value($qty, Dec::rescale($cost, Dec::MONEY, Dec::COST));
            $subtotal += $line;
            $tax += Dec::div(Dec::mul($line, $rate), 10000);
            PurchaseOrderItem::query()->create([
                'po_id' => $po->id, 'variant_id' => $variant->id, 'qty_ordered' => Dec::fmt($qty, Dec::QTY),
                'unit_cost' => Dec::fmt($cost, Dec::MONEY), 'tax_rate_percent' => Dec::fmt($rate, Dec::MONEY), 'line_total' => Dec::fmt($line, Dec::MONEY),
            ]);
        }
        $extra = Dec::parse($extraTotal, Dec::MONEY);
        $po->update([
            'subtotal' => Dec::fmt($subtotal, Dec::MONEY), 'tax_total' => Dec::fmt($tax, Dec::MONEY),
            'extra_total' => Dec::fmt($extra, Dec::MONEY), 'grand_total' => Dec::fmt($subtotal + $tax + $extra, Dec::MONEY),
        ]);
    }

    /** Variant override → product → default tax rate (BR-003), as percent at scale 2. */
    private function defaultRate(ProductVariant $variant): int
    {
        $id = $variant->tax_rate_id ?? $variant->product?->tax_rate_id;
        $rate = $id ? TaxRate::query()->find($id) : TaxRate::query()->where('is_default', true)->first();

        return $rate ? Dec::parse((string) $rate->rate_percent, Dec::MONEY) : 0;
    }

    private function activeSupplier(int $id): Supplier
    {
        $s = Supplier::query()->findOrFail($id);
        if (! $s->is_active) {
            throw new ApiException('SUPPLIER_INACTIVE', 422, '', ['supplier_id' => ['errors.SUPPLIER_INACTIVE']]);
        }

        return $s;
    }

    /** @param  array<int, string>  $from */
    private function assertStatus(PurchaseOrder $po, array $from): void
    {
        if (! in_array($po->status, $from, true)) {
            throw new ApiException('INVALID_STATE', 409, '', [], ['status' => $po->status]);
        }
    }

    /** @param  array<int, string>  $from */
    private function transition(PurchaseOrder $po, array $from, string $to, string $action, ?callable $mutate = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $from, $to, $action, $mutate): PurchaseOrder {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($po, $from);
            $before = $po->status;
            if ($mutate) {
                $mutate($po);
            }
            $po->status = $to;
            $po->save();
            $this->audit->log($action, 'purchase_orders', $po->id, ['status' => $before], ['status' => $to]);

            return $this->fresh($po);
        });
    }

    private function fresh(PurchaseOrder $po): PurchaseOrder
    {
        return $po->refresh()->load(['supplier', 'items.variant']);
    }
}
