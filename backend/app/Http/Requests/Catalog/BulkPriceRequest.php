<?php

namespace App\Http\Requests\Catalog;

use App\Services\Catalog\PriceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],
            'mode' => ['required', Rule::in(['percent', 'fixed'])],
            'amount' => ['required', 'regex:/^[+-]?\d+(\.\d{1,2})?$/'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => [Rule::in(PriceService::COLUMNS)],
            'filter' => ['required', 'array'],
            'filter.product_ids' => ['sometimes', 'array'],
            'filter.product_ids.*' => ['integer'],
            'filter.variant_ids' => ['sometimes', 'array'],
            'filter.variant_ids.*' => ['integer'],
            'filter.category_id' => ['sometimes', 'nullable', 'integer'],
            'filter.brand_id' => ['sometimes', 'nullable', 'integer'],
            'filter.mirror_type' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
