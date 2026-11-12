<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('variant') !== null);
    }

    /** @return array<string, array<int, mixed>> */
    public static function rulesFor(bool $update, string $prefix = ''): array
    {
        $req = $update ? 'sometimes' : 'required';
        $lookup = Rule::exists('lookup_values', 'id')->whereNull('deleted_at');
        $money = ['numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];
        $rules = [
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-]+$/'],
            'barcode_aliases' => ['sometimes', 'nullable', 'array', 'max:10'],
            'barcode_aliases.*' => ['string', 'max:60', 'regex:/^[A-Za-z0-9\-]+$/'],
            'position' => [$req, Rule::in(ProductVariant::POSITIONS)],
            'size_width_mm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999.9'],
            'size_height_mm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999.9'],
            'thickness_mm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.9'],
            'size_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'weight_g' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.9'],
            'glass_type_id' => ['sometimes', 'nullable', 'integer', $lookup],
            'tint_id' => ['sometimes', 'nullable', 'integer', $lookup],
            'color_finish_id' => ['sometimes', 'nullable', 'integer', $lookup],
            'adjustment' => ['sometimes', 'nullable', Rule::in(ProductVariant::ADJUSTMENTS)],
            'pin_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'connector_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'supplier_part_no' => ['sometimes', 'nullable', 'string', 'max:191'],
            'year_from' => ['sometimes', 'nullable', 'integer', 'between:1950,2100'],
            'year_to' => ['sometimes', 'nullable', 'integer', 'between:1950,2100', 'gte:year_from'],
            'retail_price' => [$req, ...$money],
            'wholesale_price' => ['sometimes', 'nullable', ...$money],
            'dealer_price' => ['sometimes', 'nullable', ...$money],
            'mrp' => ['sometimes', 'nullable', ...$money],
            'min_price' => ['sometimes', 'nullable', ...$money],
            'tax_rate_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tax_rates', 'id')->whereNull('deleted_at')],
            'reorder_level' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'reorder_qty' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'max_stock' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'default_location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('storage_locations', 'id')->whereNull('deleted_at')],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'custom' => ['sometimes', 'nullable', 'array'],
            'generate_barcode' => ['sometimes', 'boolean'],
        ];
        foreach (['is_glass_only', 'cover_included', 'track_serial', 'is_bundle', 'is_discontinued', 'is_active', ...ProductVariant::FEATURE_FLAGS] as $flag) {
            $rules[$flag] = ['sometimes', 'boolean'];
        }
        if ($prefix === '') {
            return $rules;
        }

        return collect($rules)->mapWithKeys(fn ($v, $k) => [$prefix.$k => $v])->all();
    }
}
