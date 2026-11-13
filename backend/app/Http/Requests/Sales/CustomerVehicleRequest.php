<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reg_no' => ['required', 'string', 'max:40'],
            'make_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vehicle_makes', 'id')],
            'model_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vehicle_models', 'id')],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1950,2100'],
            'color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
