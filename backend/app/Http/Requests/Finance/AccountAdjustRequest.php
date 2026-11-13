<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
