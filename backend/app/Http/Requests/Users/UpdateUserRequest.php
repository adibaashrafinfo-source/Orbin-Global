<?php

namespace App\Http\Requests\Users;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at')],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^01\d{9}$/'],
            'role' => ['sometimes', 'required', Rule::in(Permissions::ROLES)],
            'default_branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'branch_ids' => ['sometimes', 'nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'language' => ['sometimes', 'in:en,bn'],
            'must_change_password' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
