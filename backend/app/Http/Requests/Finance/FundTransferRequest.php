<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FundTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', Rule::exists('financial_accounts', 'id')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'fee_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'transfer_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
