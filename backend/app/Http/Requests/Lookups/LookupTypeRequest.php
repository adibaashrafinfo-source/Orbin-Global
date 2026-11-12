<?php

namespace App\Http\Requests\Lookups;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LookupTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('type')?->id;
        $req = $id ? 'sometimes' : 'required';

        return [
            'key' => [$id ? 'prohibited' : 'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('lookup_types', 'key')],
            'name_en' => [$req, 'string', 'max:255'],
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
