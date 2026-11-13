<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cash_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')],
            'opening_float' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }
}
