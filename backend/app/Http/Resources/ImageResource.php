<?php

namespace App\Http\Resources;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductImage */
class ImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->only(['id', 'product_id', 'variant_id', 'url', 'thumb_url', 'label', 'alt_text', 'sort_order', 'is_primary']);
    }
}
