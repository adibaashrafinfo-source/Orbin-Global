<?php

namespace App\Http\Resources;

use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleReturn */
class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->only(['id', 'return_no', 'sale_id', 'customer_id', 'status', 'reason_id', 'refund_mode', 'refund_method', 'refund_account_id', 'subtotal', 'tax_total', 'refund_total', 'ledger_credit_total', 'note', 'approved_by']),
            'return_date' => $this->return_date?->toIso8601String(),
            'has_pdf' => (bool) $this->pdf_path,
            'invoice_no' => $this->whenLoaded('sale', fn () => $this->sale?->invoice_no),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => $i->only(['id', 'sale_item_id', 'variant_id', 'qty', 'unit_price', 'tax_amount', 'line_total', 'condition', 'restock_warehouse_id']))->values()),
        ];
    }
}
