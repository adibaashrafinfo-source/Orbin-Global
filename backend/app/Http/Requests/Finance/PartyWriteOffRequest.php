<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartyWriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $table = $this->input('party_type') === 'supplier' ? 'suppliers' : 'customers';

        return [
            'party_type' => ['required', Rule::in(['customer', 'supplier'])],
            'party_id' => ['required', 'integer', Rule::exists($table, 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'kind' => ['required', Rule::in(['writeoff', 'discount'])],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
