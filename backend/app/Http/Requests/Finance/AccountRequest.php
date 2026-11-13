<?php

namespace App\Http\Requests\Finance;

use App\Models\FinancialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $account = $this->route('account');
        $id = $account instanceof FinancialAccount ? $account->id : null;
        $req = $id === null ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:40', Rule::unique('financial_accounts', 'code')->ignore($id)],
            'name' => [$req, 'string', 'max:160'],
            'account_type' => [$req, Rule::in(FinancialAccount::TYPES)],
            'provider' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bank_branch' => ['sometimes', 'nullable', 'string', 'max:120'],
            'opening_balance' => ['sometimes', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'opening_date' => ['sometimes', 'date_format:Y-m-d'],
            'is_default_for_method' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
