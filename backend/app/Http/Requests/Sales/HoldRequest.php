<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'held_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')],
            'warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('warehouses', 'id')],
            'payload' => ['required', 'array'],
        ];
    }
}
