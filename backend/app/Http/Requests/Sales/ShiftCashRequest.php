<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }
}
