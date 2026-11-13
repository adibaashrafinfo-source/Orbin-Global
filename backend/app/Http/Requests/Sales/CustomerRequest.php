<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('customer')?->id;
        $req = $id ? 'sometimes' : 'required';
        $money = ['numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];

        return [
            'name' => [$req, 'string', 'max:200'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'customer_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lookup_values', 'id')],
            'price_tier' => ['sometimes', Rule::in(['retail', 'wholesale', 'dealer'])],
            'phones' => [$req, 'array', 'min:1', 'max:5'],
            'phones.*' => ['required', 'string', 'regex:/^01[3-9][0-9]{8}$/'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'district' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postcode' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nid_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'trade_license_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bin_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'credit_limit' => ['sometimes', ...$money],
            'opening_balance' => [$id ? 'prohibited' : 'sometimes', ...$money],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'custom' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'acknowledge_duplicate' => ['sometimes', 'boolean'],
        ];
    }
}
