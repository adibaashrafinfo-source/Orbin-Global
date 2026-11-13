<?php

namespace App\Http\Resources;

use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quotation */
class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->only(['id', 'quote_no', 'customer_id', 'customer_snapshot', 'vehicle_snapshot', 'status', 'subtotal', 'discount_total', 'tax_total', 'delivery_charge', 'grand_total', 'note', 'converted_sale_id']),
            'quote_date' => $this->quote_date?->toDateString(), 'valid_until' => $this->valid_until?->toDateString(), 'has_pdf' => (bool) $this->pdf_path,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => $i->only(['id', 'variant_id', 'description_snapshot', 'qty', 'unit_price', 'discount_type', 'discount_value', 'tax_rate_percent', 'line_total']))->values()),
        ];
    }
}
