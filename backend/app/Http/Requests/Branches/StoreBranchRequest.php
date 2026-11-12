<?php

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('branch')?->id;

        return [
            'code' => [$id ? 'sometimes' : 'required', 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('branches', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
