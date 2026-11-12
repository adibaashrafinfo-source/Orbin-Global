<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNumberSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/'],
            'date_token' => ['present', 'nullable', 'string', 'max:10', 'regex:/^(YYYY|YY|MM|DD)*$/'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
            'reset_policy' => ['required', 'in:never,yearly,monthly'],
            'next_value' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
