<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One shape for purchase orders, goods receipts and purchase returns: the header columns, the supplier and warehouse
 * names, and the lines with SKU and name for display.
 */
class PurchaseDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource->attributesToArray();
        unset($data['created_by'], $data['updated_by'], $data['deleted_at'], $data['pdf_path']);
        if ($this->resource->relationLoaded('supplier')) {
            $data['supplier'] = $this->supplier?->only(['id', 'code', 'name']);
        }
        if ($this->resource->relationLoaded('warehouse')) {
            $data['warehouse'] = $this->warehouse?->only(['id', 'code', 'name']);
        }
        foreach (['items' => 'items', 'extraCosts' => 'extra_costs'] as $relation => $key) {
            if ($this->resource->relationLoaded($relation)) {
                $data[$key] = $this->{$relation}->map(function ($row) {
                    $out = $row->attributesToArray();
                    unset($out['created_by'], $out['updated_by'], $out['deleted_at']);
                    if ($row->relationLoaded('variant') && $row->variant) {
                        $out['sku'] = $row->variant->sku;
                        $out['variant_name'] = $row->variant->variant_name;
                    }

                    return $out;
                })->values();
            }
        }

        return $data;
    }
}
