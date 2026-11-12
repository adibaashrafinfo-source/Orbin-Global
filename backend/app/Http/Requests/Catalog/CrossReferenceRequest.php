<?php

namespace App\Http\Requests\Catalog;

use App\Models\OemCrossReference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrossReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ref_type' => ['sometimes', Rule::in(OemCrossReference::TYPES)],
            'number' => ['required', 'string', 'max:191'],
            'brand' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
