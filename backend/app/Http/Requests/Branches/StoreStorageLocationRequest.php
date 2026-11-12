<?php

namespace App\Http\Requests\Branches;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStorageLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('storage_location')?->id;
        $req = $id ? 'sometimes' : 'required';
        $warehouseId = $this->input('warehouse_id', $this->route('storage_location')?->warehouse_id);

        return [
            'warehouse_id' => [$req, 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'parent_id' => ['nullable', 'integer', Rule::exists('storage_locations', 'id')->where('warehouse_id', $warehouseId)->whereNull('deleted_at')],
            'code' => [$req, 'string', 'max:30', Rule::unique('storage_locations', 'code')->where('warehouse_id', $warehouseId)->ignore($id)->whereNull('deleted_at')],
            'name' => ['nullable', 'string', 'max:255'],
            'kind' => ['sometimes', 'in:rack,shelf,bin'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
