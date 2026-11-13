<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class ShiftCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'denomination_counts' => ['required', 'array'],
            'denomination_counts.*' => ['integer', 'min:0', 'max:1000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
