<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cost columns are omitted unless the user holds products.view_cost (AT-019): the field is absent, not hidden in the UI.
 *
 * @mixin ProductVariant
 */
class VariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canCost = $user && app(PermissionService::class)->userHas($user, 'products.view_cost');
        $data = [
            'id' => $this->id, 'product_id' => $this->product_id, 'sku' => $this->sku, 'barcode' => $this->barcode, 'barcode_aliases' => $this->barcode_aliases ?? [],
            'variant_name' => $this->variant_name, 'position' => $this->position, 'size_width_mm' => $this->size_width_mm, 'size_height_mm' => $this->size_height_mm,
            'thickness_mm' => $this->thickness_mm, 'size_label' => $this->size_label, 'weight_g' => $this->weight_g,
            'glass_type_id' => $this->glass_type_id, 'tint_id' => $this->tint_id, 'color_finish_id' => $this->color_finish_id,
            'is_glass_only' => $this->is_glass_only, 'cover_included' => $this->cover_included, 'adjustment' => $this->adjustment,
            'heated' => $this->heated, 'auto_fold' => $this->auto_fold, 'turn_signal' => $this->turn_signal, 'blind_spot_sensor' => $this->blind_spot_sensor,
            'side_camera' => $this->side_camera, 'memory_tilt' => $this->memory_tilt, 'auto_dimming' => $this->auto_dimming, 'puddle_light' => $this->puddle_light,
            'pin_count' => $this->pin_count, 'connector_note' => $this->connector_note, 'supplier_part_no' => $this->supplier_part_no,
            'year_from' => $this->year_from, 'year_to' => $this->year_to,
            'retail_price' => $this->retail_price, 'wholesale_price' => $this->wholesale_price, 'dealer_price' => $this->dealer_price, 'mrp' => $this->mrp, 'min_price' => $this->min_price,
            'tax_rate_id' => $this->tax_rate_id, 'reorder_level' => $this->reorder_level, 'reorder_qty' => $this->reorder_qty, 'max_stock' => $this->max_stock,
            'default_location_id' => $this->default_location_id, 'track_serial' => $this->track_serial, 'is_bundle' => $this->is_bundle,
            'is_discontinued' => $this->is_discontinued, 'is_active' => $this->is_active, 'internal_note' => $this->internal_note, 'custom' => $this->custom ?? (object) [],
            'stock_on_hand' => isset($this->stock_on_hand) ? number_format((float) $this->stock_on_hand, 3, '.', '') : (array_key_exists('stock_on_hand', $this->resource->getAttributes()) ? '0.000' : null),
            'glass_type' => $this->whenLoaded('glassType', fn () => $this->glassType?->label_en),
            'tint' => $this->whenLoaded('tint', fn () => $this->tint?->label_en),
            'color_finish' => $this->whenLoaded('colorFinish', fn () => $this->colorFinish?->label_en),
            'cross_references' => $this->whenLoaded('crossReferences', fn () => $this->crossReferences->map(fn ($x) => $x->only(['id', 'ref_type', 'number', 'number_normalized', 'brand', 'note']))->values()),
            'fitments' => $this->whenLoaded('fitments', fn () => $this->fitments->map(fn ($f) => [
                'id' => $f->id, 'vehicle_model_id' => $f->vehicle_model_id, 'year_from' => $f->year_from, 'year_to' => $f->year_to, 'trim' => $f->trim, 'note' => $f->note,
                'make' => $f->vehicleModel?->make?->name, 'model' => $f->vehicleModel?->name, 'generation_code' => $f->vehicleModel?->generation_code,
            ])->values()),
            'images' => $this->whenLoaded('images', fn () => ImageResource::collection($this->images)),
            'bundle_items' => $this->whenLoaded('bundleItems', fn () => $this->bundleItems->map(fn ($b) => [
                'id' => $b->id, 'component_variant_id' => $b->component_variant_id, 'qty' => $b->qty, 'sku' => $b->component?->sku, 'variant_name' => $b->component?->variant_name,
            ])->values()),
        ];
        if ($canCost) {
            $data['last_cost'] = $this->last_cost;
        }

        return $data;
    }
}
