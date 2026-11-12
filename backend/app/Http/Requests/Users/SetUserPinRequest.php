<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class SetUserPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['pin' => ['present', 'nullable', 'string', 'regex:/^\d{4,6}$/']];
    }
}
