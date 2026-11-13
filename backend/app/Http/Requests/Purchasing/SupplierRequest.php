<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('supplier')?->id;
        $req = $id ? 'sometimes' : 'required';
        $money = ['numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];

        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-]*$/', Rule::unique('suppliers', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => [$req, 'string', 'max:191'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:191'],
            'phones' => ['sometimes', 'nullable', 'array', 'max:5'],
            'phones.*' => ['string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'city' => ['sometimes', 'nullable', 'string', 'max:191'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'supplier_type' => ['sometimes', Rule::in(['local', 'import'])],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['sometimes', ...$money],
            'opening_balance' => [$id ? 'prohibited' : 'sometimes', ...$money],
            'bank_details' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'custom' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
