<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')],
            'expense_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'payee' => ['sometimes', 'nullable', 'string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'attachment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('attachments', 'id')],
        ];
    }
}
