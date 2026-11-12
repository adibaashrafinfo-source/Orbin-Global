<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name_en' => $this->name_en, 'name_bn' => $this->name_bn, 'mirror_type' => $this->mirror_type,
            'category_id' => $this->category_id, 'brand_id' => $this->brand_id, 'quality_grade_id' => $this->quality_grade_id, 'origin_country_id' => $this->origin_country_id,
            'short_description' => $this->short_description, 'description' => $this->description, 'warranty_months' => $this->warranty_months,
            'return_window_days' => $this->return_window_days, 'tax_rate_id' => $this->tax_rate_id, 'unit_id' => $this->unit_id, 'tags' => $this->tags ?? [],
            'primary_image_url' => $this->primary_image_url, 'custom' => $this->custom ?? (object) [], 'is_active' => $this->is_active,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name_en),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand?->name),
            'quality_grade' => $this->whenLoaded('qualityGrade', fn () => $this->qualityGrade?->label_en),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->code),
            'variants_count' => $this->whenCounted('variants'),
            'min_price' => $this->variants_min_retail_price ?? null,
            'max_price' => $this->variants_max_retail_price ?? null,
            'stock_total' => null, // arrives with inventory in Phase 2
            'variants' => $this->whenLoaded('variants', fn () => VariantResource::collection($this->variants)),
            'images' => $this->whenLoaded('images', fn () => ImageResource::collection($this->images)),
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
