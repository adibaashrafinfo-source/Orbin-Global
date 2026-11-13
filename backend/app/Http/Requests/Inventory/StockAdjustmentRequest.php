<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $update = $this->route('stockAdjustment') !== null;
        $req = $update ? 'sometimes' : 'required';

        return [
            'warehouse_id' => [$update ? 'prohibited' : 'required', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'reason_id' => [$req, 'integer', Rule::exists('lookup_values', 'id')],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => [$req, 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.qty_delta' => ['required', 'numeric', 'not_in:0', 'between:-9999999,9999999', 'decimal:0,3'],
            'items.*.unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],
            'items.*.is_breakage' => ['sometimes', 'boolean'],
            'items.*.supplier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'items.*.photo_attachment_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
