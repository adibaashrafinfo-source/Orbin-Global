<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $update = $this->route('purchaseOrder') !== null;
        $req = $update ? 'sometimes' : 'required';

        return [
            'supplier_id' => [$req, 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'order_date' => [$req, 'date_format:Y-m-d'],
            'expected_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'extra_total' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'items' => [$req, 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.qty_ordered' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,3'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'items.*.tax_rate_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }
}
