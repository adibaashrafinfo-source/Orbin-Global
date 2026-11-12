<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    public function rules(): array
    {
        $update = $this->route('product') !== null;
        $req = $update ? 'sometimes' : 'required';
        $live = fn (string $table) => Rule::exists($table, 'id')->whereNull('deleted_at');

        return [
            'code' => [$req, 'string', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/'],
            'name_en' => [$req, 'string', 'max:191'],
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:191'],
            'mirror_type' => [$req, Rule::in(Product::MIRROR_TYPES)],
            'category_id' => [$req, 'integer', $live('categories')],
            'brand_id' => ['sometimes', 'nullable', 'integer', $live('product_brands')],
            'quality_grade_id' => [$req, 'integer', $live('lookup_values')],
            'origin_country_id' => ['sometimes', 'nullable', 'integer', $live('lookup_values')],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'warranty_months' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'return_window_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'tax_rate_id' => ['sometimes', 'nullable', 'integer', $live('tax_rates')],
            'unit_id' => [$req, 'integer', $live('units')],
            'tags' => ['sometimes', 'nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
            'custom' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
