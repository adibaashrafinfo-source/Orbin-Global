<?php

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('warehouse')?->id;
        $req = $id ? 'sometimes' : 'required';

        return [
            'branch_id' => [$req, 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('warehouses', 'code')->ignore($id)->whereNull('deleted_at')],
            'name' => [$req, 'string', 'max:255'],
            'kind' => ['sometimes', 'in:showroom,godown'],
            'is_default' => ['sometimes', 'boolean'],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
