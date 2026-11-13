<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $update = $this->route('purchaseReturn') !== null;
        $req = $update ? 'sometimes' : 'required';

        return [
            'supplier_id' => [$update ? 'prohibited' : 'required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'warehouse_id' => [$update ? 'prohibited' : 'required', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'grn_id' => [$update ? 'prohibited' : 'sometimes', 'nullable', 'integer', Rule::exists('goods_receipts', 'id')->whereNull('deleted_at')],
            'return_date' => [$req, 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => [$req, 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,3'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }
}
