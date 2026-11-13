<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'scope' => ['sometimes', Rule::in(['full', 'category', 'location'])],
            'scope_ref' => ['sometimes', 'nullable', 'integer'],
            'is_blind' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
