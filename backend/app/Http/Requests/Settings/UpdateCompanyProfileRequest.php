<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware gates the route
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'address_line1' => ['present', 'nullable', 'string', 'max:1000'],
            'address_line2' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'phones' => ['present', 'array', 'max:5'],
            'phones.*' => ['string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'bin_no' => ['nullable', 'string', 'max:50'],
            'tin_no' => ['nullable', 'string', 'max:50'],
            'trade_license_no' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:50'],
            'invoice_terms' => ['nullable', 'string', 'max:5000'],
            'return_policy_text' => ['nullable', 'string', 'max:5000'],
            'warranty_policy_text' => ['nullable', 'string', 'max:5000'],
            'footer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->phones)) {
            $this->merge(['phones' => array_values(array_filter(array_map(fn ($p) => trim((string) $p), $this->phones), fn ($p) => $p !== ''))]);
        }
        $this->merge(['address_line1' => (string) ($this->address_line1 ?? '')]);
    }
}
