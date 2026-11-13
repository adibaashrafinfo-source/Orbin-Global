<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarrantyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['in_review', 'replaced', 'refunded', 'rejected'])],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'replacement_variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_variants', 'id')],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
