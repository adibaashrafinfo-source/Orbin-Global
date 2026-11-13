<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name_en' => [$req, 'string', 'max:160'],
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:160'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
