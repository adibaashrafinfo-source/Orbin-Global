<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Laravel's validated() drops array items that have nested rules but no content, and an empty feature set means
     * "no extra features". Feature sets are therefore read from the request and filtered to the allowed keys here.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        $sets = $this->input('feature_sets');
        if (is_array($sets)) {
            $allowed = array_flip([...ProductVariant::FEATURE_FLAGS, 'adjustment']);
            $data['feature_sets'] = array_map(fn ($set) => array_intersect_key((array) $set, $allowed), array_values($sets));
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }

    public function rules(): array
    {
        $defaults = collect(VariantRequest::rulesFor(true, 'defaults.'))->except(['defaults.sku', 'defaults.barcode', 'defaults.barcode_aliases', 'defaults.position', 'defaults.generate_barcode'])->all();
        $flags = collect(ProductVariant::FEATURE_FLAGS)->mapWithKeys(fn ($f) => ["feature_sets.*.{$f}" => ['sometimes', 'boolean']])->all();

        return array_merge($defaults, $flags, [
            'dry_run' => ['sometimes', 'boolean'],
            'positions' => ['required', 'array', 'min:1', 'max:5'],
            'positions.*' => ['string', Rule::in(ProductVariant::POSITIONS)],
            'sizes' => ['sometimes', 'array', 'max:40'],
            'sizes.*' => ['array'],
            'sizes.*.width_mm' => ['nullable', 'numeric', 'min:0', 'max:99999.9'],
            'sizes.*.height_mm' => ['nullable', 'numeric', 'min:0', 'max:99999.9'],
            'sizes.*.thickness_mm' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'sizes.*.label' => ['nullable', 'string', 'max:100'],
            'color_finish_ids' => ['sometimes', 'array', 'max:20'],
            'color_finish_ids.*' => ['integer', Rule::exists('lookup_values', 'id')->whereNull('deleted_at')],
            'feature_sets' => ['sometimes', 'array', 'max:20'],
            'feature_sets.*' => ['array'], // an empty set means "no extra features" and must be kept
            'feature_sets.*.adjustment' => ['sometimes', 'nullable', Rule::in(ProductVariant::ADJUSTMENTS)],
            'defaults' => ['required', 'array'],
            'defaults.retail_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
    }
}
