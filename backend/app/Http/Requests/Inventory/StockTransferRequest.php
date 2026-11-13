<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $update = $this->route('stockTransfer') !== null;
        $req = $update ? 'sometimes' : 'required';
        $wh = Rule::exists('warehouses', 'id')->whereNull('deleted_at');

        return [
            'from_warehouse_id' => [$update ? 'prohibited' : 'required', 'integer', $wh],
            'to_warehouse_id' => [$update ? 'prohibited' : 'required', 'integer', $wh],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => [$req, 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.qty_sent' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,3'],
            'items.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
