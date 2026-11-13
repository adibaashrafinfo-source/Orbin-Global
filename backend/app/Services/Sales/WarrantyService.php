<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\AuditService;
use App\Services\Inventory\StockPostingService;
use App\Services\NumberService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-717: a claim against a sold line inside its warranty; a replacement leaves stock through the one stock service. */
class WarrantyService
{
    public function __construct(
        private readonly NumberService $numbers,
        private readonly StockPostingService $stock,
        private readonly AuditService $audit,
    ) {}

    /** @param  array<string, mixed>  $in  {sale_item_id, issue, photo_attachment_id?, note?} */
    public function create(User $user, array $in): WarrantyClaim
    {
        $item = SaleItem::query()->findOrFail((int) $in['sale_item_id']);
        $sale = DB::table('sales')->where('id', $item->sale_id)->first();
        if ($sale === null || ! in_array($sale->status, ['completed', 'partially_returned'], true)) {
            throw new ApiException('INVALID_STATE', 409);
        }
        if ($item->warranty_until === null || $item->warranty_until->toDateString() < Branches::today()) {
            throw new ApiException('WARRANTY_EXPIRED', 422, '', [], ['warranty_until' => $item->warranty_until?->toDateString()]);
        }

        return DB::transaction(function () use ($in, $item, $sale): WarrantyClaim {
            $claim = WarrantyClaim::query()->create([
                'branch_id' => $sale->branch_id, 'claim_no' => $this->numbers->next('warranty_claim', $sale->branch_id), 'sale_item_id' => $item->id, 'customer_id' => $sale->customer_id,
                'status' => 'open', 'issue' => $in['issue'], 'claimed_at' => now(), 'photo_attachment_id' => $in['photo_attachment_id'] ?? null, 'note' => $in['note'] ?? null,
            ]);
            $this->audit->log('warranty_claim', 'warranty_claims', $claim->id, null, ['claim_no' => $claim->claim_no, 'sale_item_id' => $item->id]);

            return $claim;
        });
    }

    /** @param  array<string, mixed>  $in  {status: in_review|replaced|refunded|rejected, resolution?, replacement_variant_id?, note?} */
    public function update(User $user, WarrantyClaim $claim, array $in): WarrantyClaim
    {
        if (in_array($claim->status, ['replaced', 'refunded', 'rejected'], true)) {
            throw new ApiException('INVALID_STATE', 409);
        }
        $status = (string) $in['status'];

        return DB::transaction(function () use ($user, $claim, $in, $status): WarrantyClaim {
            $locked = WarrantyClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['replaced', 'refunded', 'rejected'], true)) {
                throw new ApiException('INVALID_STATE', 409);
            }
            $data = ['status' => $status, 'note' => $in['note'] ?? $locked->note];
            if ($status === 'replaced') {
                $variantId = (int) ($in['replacement_variant_id'] ?? DB::table('sale_items')->where('id', $locked->sale_item_id)->value('variant_id'));
                $warehouseId = (int) DB::table('sales as s')->join('sale_items as i', 'i.sale_id', '=', 's.id')->where('i.id', $locked->sale_item_id)->value('s.warehouse_id');
                $this->stock->post(['variant_id' => $variantId, 'warehouse_id' => $warehouseId, 'type' => 'adjustment_out', 'qty' => Dec::fmt(-Dec::parse('1', Dec::QTY), Dec::QTY),
                    'ref_table' => 'warranty_claims', 'ref_id' => $locked->id, 'ref_no' => $locked->claim_no, 'reason' => 'warranty_replacement']);
                $data['replacement_variant_id'] = $variantId;
            }
            if (in_array($status, ['replaced', 'refunded', 'rejected'], true)) {
                $data += ['resolution' => $in['resolution'] ?? $status, 'resolved_at' => now(), 'resolved_by' => $user->id];
            }
            $locked->forceFill($data)->save();
            $this->audit->log('warranty_'.$status, 'warranty_claims', $locked->id, ['status' => $claim->status], ['status' => $status, 'resolution' => $data['resolution'] ?? null]);

            return $locked->refresh();
        }, 3);
    }
}
