<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $table = $this->input('party_type') === 'supplier' ? 'suppliers' : 'customers';

        return [
            'party_type' => ['required', Rule::in(['customer', 'supplier'])],
            'party_id' => ['required', 'integer', Rule::exists($table, 'id')->whereNull('deleted_at')],
            'method' => ['required', Rule::in(['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('financial_accounts', 'id')],
            'sender_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'trx_id' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bank_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bank_account_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'transfer_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'payment_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'auto_oldest_first' => ['sometimes', 'boolean'],
            'allocations' => ['sometimes', 'array', 'max:200'],
            'allocations.*.doc_table' => ['required_with:allocations', Rule::in(['sales', 'goods_receipts', 'party_ledger_entries'])],
            'allocations.*.doc_id' => ['required_with:allocations', 'integer'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'attachment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('attachments', 'id')],
            'approvals' => ['sometimes', 'array'],
            'approvals.manager_pin' => ['sometimes', 'nullable', 'string', 'max:10'],
            'approvals.reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
