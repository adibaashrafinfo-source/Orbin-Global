<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FitmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_model_id' => ['required', 'integer', Rule::exists('vehicle_models', 'id')->whereNull('deleted_at')],
            'year_from' => ['required', 'integer', 'between:1950,2100'],
            'year_to' => ['nullable', 'integer', 'between:1950,2100', 'gte:year_from'],
            'trim' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
            'apply_to_all_variants' => ['sometimes', 'boolean'],
        ];
    }
}
