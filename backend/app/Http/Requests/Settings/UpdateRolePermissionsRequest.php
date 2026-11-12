<?php

namespace App\Http\Requests\Settings;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            foreach (array_keys((array) $this->input('permissions', [])) as $key) {
                if (! in_array($key, Permissions::KEYS, true)) {
                    $v->errors()->add('permissions.'.$key, 'Unknown permission key.');
                }
            }
            if (! in_array($this->route('role'), Permissions::ROLES, true) || $this->route('role') === 'super_admin') {
                $v->errors()->add('role', 'Role cannot be edited.');
            }
        });
    }
}
