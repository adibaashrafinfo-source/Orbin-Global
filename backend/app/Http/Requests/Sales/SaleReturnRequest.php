<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', Rule::exists('sales', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.sale_item_id' => ['required', 'integer', Rule::exists('sale_items', 'id')],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
            'items.*.condition' => ['required', Rule::in(['resellable', 'broken'])],
            'items.*.restock_warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('warehouses', 'id')],
            'reason_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lookup_values', 'id')],
            'refund_mode' => ['required', Rule::in(['original_method', 'cash', 'other_method', 'ledger_credit'])],
            'refund_method' => ['sometimes', 'nullable', Rule::in(['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer'])],
            'refund_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('financial_accounts', 'id')],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
