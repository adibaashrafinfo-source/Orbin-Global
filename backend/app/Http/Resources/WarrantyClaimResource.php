<?php

namespace App\Http\Resources;

use App\Models\WarrantyClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarrantyClaim */
class WarrantyClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->only(['id', 'claim_no', 'sale_item_id', 'customer_id', 'status', 'issue', 'photo_attachment_id', 'resolution', 'replacement_variant_id', 'resolved_by', 'note']),
            'claimed_at' => $this->claimed_at?->toIso8601String(), 'resolved_at' => $this->resolved_at?->toIso8601String(),
            'customer' => $this->customer_name ?? null, 'invoice_no' => $this->invoice_no ?? null, 'product' => $this->product_name ?? null,
        ];
    }
}
