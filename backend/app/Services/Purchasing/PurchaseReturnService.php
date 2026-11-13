<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Services\Inventory\StockPostingService;
use App\Services\NumberService;
use App\Support\Dec;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** FR-504. Sending goods back takes stock out at the current average and lowers what we owe the supplier. */
class PurchaseReturnService
{
    public function __construct(
        private readonly NumberService $numbers,
        private readonly AuditService $audit,
        private readonly PeriodLockService $period,
        private readonly StockPostingService $stock,
        private readonly SupplierService $suppliers,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data): PurchaseReturn {
            $this->period->assertOpen($data['return_date']);
            $warehouse = Warehouse::query()->findOrFail((int) $data['warehouse_id']);
            Supplier::query()->findOrFail((int) $data['supplier_id']);
            if (! empty($data['grn_id'])) {
                $grn = GoodsReceipt::query()->findOrFail((int) $data['grn_id']);
                if ($grn->status !== 'posted' || $grn->supplier_id !== (int) $data['supplier_id']) {
                    throw new ApiException('VALIDATION', 422, '', ['grn_id' => ['errors.GRN_MISMATCH']]);
                }
            }
            $return = PurchaseReturn::query()->create([
                'branch_id' => $warehouse->branch_id, 'return_no' => $this->numbers->next('purchase_return', $warehouse->branch_id, Carbon::parse($data['return_date'])),
                'supplier_id' => $data['supplier_id'], 'grn_id' => $data['grn_id'] ?? null, 'warehouse_id' => $warehouse->id,
                'status' => 'draft', 'return_date' => $data['return_date'], 'reason' => $data['reason'] ?? null,
            ]);
            $this->writeItems($return, $data['items']);

            return $this->fresh($return);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function update(PurchaseReturn $return, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $data): PurchaseReturn {
            $return = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($return);
            if (isset($data['return_date'])) {
                $this->period->assertOpen($data['return_date']);
            }
            $return->update(array_intersect_key($data, array_flip(['return_date', 'reason'])));
            if (isset($data['items'])) {
                $this->writeItems($return, $data['items']);
            }

            return $this->fresh($return);
        }, 3);
    }

    public function cancel(PurchaseReturn $return): PurchaseReturn
    {
        return DB::transaction(function () use ($return): PurchaseReturn {
            $return = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($return);
            $return->update(['status' => 'cancelled']);

            return $this->fresh($return);
        });
    }

    public function post(PurchaseReturn $return): PurchaseReturn
    {
        return DB::transaction(function () use ($return): PurchaseReturn {
            $r = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($r->grn_id) {
                // Returns against one receipt queue up here, before any plain read, so each sees the earlier ones' totals.
                GoodsReceipt::query()->whereKey($r->grn_id)->lockForUpdate()->firstOrFail();
            }
            $this->assertDraft($r);
            $this->period->assertOpen($r->return_date);
            $items = $r->items()->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw new ApiException('EMPTY_RECEIPT', 422);
            }
            if ($r->grn_id) {
                $this->assertWithinReceipt($r, $items);
            }
            $lines = [];
            $total = 0;
            foreach ($items as $item) {
                $lines[] = ['variant_id' => $item->variant_id, 'warehouse_id' => $r->warehouse_id, 'type' => 'purchase_return', 'qty' => Dec::fmt(-Dec::parse((string) $item->qty, Dec::QTY), Dec::QTY),
                    'ref_table' => 'purchase_returns', 'ref_id' => $r->id, 'ref_no' => $r->return_no, 'reason' => $r->reason, 'occurred_at' => now()];
                $total += Dec::value(Dec::parse((string) $item->qty, Dec::QTY), Dec::rescale(Dec::parse((string) $item->unit_cost, Dec::MONEY), Dec::MONEY, Dec::COST));
            }
            $this->stock->postMany($lines);
            $r->update(['total' => Dec::fmt($total, Dec::MONEY), 'status' => 'posted', 'posted_at' => now()]);
            if ($total > 0) {
                $this->suppliers->ledger($r->supplier, 'return', $total, 0, 'purchase_returns', $r->id, 'Purchase return '.$r->return_no, $r->return_no, $r->return_date->toDateString(), $r->branch_id);
            }
            $this->audit->log('post', 'purchase_returns', $r->id, ['status' => 'draft'], ['status' => 'posted', 'return_no' => $r->return_no, 'total' => Dec::fmt($total, Dec::MONEY)]);

            return $this->fresh($r);
        }, 3);
    }

    /** A return against a receipt cannot exceed what that receipt brought in, less earlier posted returns. */
    private function assertWithinReceipt(PurchaseReturn $r, $items): void
    {
        foreach ($items->groupBy('variant_id') as $variantId => $group) {
            $received = (string) DB::table('goods_receipt_items')->where(['grn_id' => $r->grn_id, 'variant_id' => $variantId])->whereNull('deleted_at')->selectRaw('COALESCE(SUM(qty_received - qty_damaged), 0) as q')->value('q');
            $returned = (string) DB::table('purchase_return_items as i')->join('purchase_returns as p', 'p.id', '=', 'i.return_id')
                ->where(['p.grn_id' => $r->grn_id, 'p.status' => 'posted', 'i.variant_id' => $variantId])->whereNull('i.deleted_at')->selectRaw('COALESCE(SUM(i.qty), 0) as q')->value('q');
            $want = $group->sum(fn ($i) => Dec::parse((string) $i->qty, Dec::QTY));
            $room = Dec::parse($received, Dec::QTY) - Dec::parse($returned, Dec::QTY);
            if ($want > $room) {
                throw new ApiException('RETURN_EXCEEDS_RECEIPT', 422, '', [], ['variant_id' => (int) $variantId, 'received' => $received, 'already_returned' => $returned, 'requested' => Dec::fmt($want, Dec::QTY)]);
            }
        }
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function writeItems(PurchaseReturn $return, array $items): void
    {
        $return->items()->forceDelete();
        $total = 0;
        foreach ($items as $row) {
            $variant = ProductVariant::query()->findOrFail((int) $row['variant_id']);
            $qty = Dec::parse((string) $row['qty'], Dec::QTY);
            $cost = Dec::parse((string) $row['unit_cost'], Dec::MONEY);
            $total += Dec::value($qty, Dec::rescale($cost, Dec::MONEY, Dec::COST));
            PurchaseReturnItem::query()->create(['return_id' => $return->id, 'variant_id' => $variant->id, 'qty' => Dec::fmt($qty, Dec::QTY), 'unit_cost' => Dec::fmt($cost, Dec::MONEY)]);
        }
        $return->update(['total' => Dec::fmt($total, Dec::MONEY)]);
    }

    private function assertDraft(PurchaseReturn $r): void
    {
        if ($r->status !== 'draft') {
            throw new ApiException('INVALID_STATE', 409, '', [], ['status' => $r->status]);
        }
    }

    private function fresh(PurchaseReturn $r): PurchaseReturn
    {
        return $r->refresh()->load(['supplier', 'warehouse', 'items.variant']);
    }
}
