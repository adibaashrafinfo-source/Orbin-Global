<?php

namespace App\Http\Requests\Lookups;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LookupValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $value = $this->route('value');
        $typeId = $value?->type_id ?? $this->route('type')?->id;
        $req = $value ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', Rule::unique('lookup_values', 'code')->where('type_id', $typeId)->ignore($value?->id)->whereNull('deleted_at')],
            'label_en' => [$req, 'string', 'max:255'],
            'label_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
