<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'party_type' => ['required', Rule::in(['customer', 'supplier'])],
            'party_id' => ['required', 'integer'],
            'promise_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'promise_note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
