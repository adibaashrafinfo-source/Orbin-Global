<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuditLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array'],
            'filter.action' => ['sometimes', 'nullable', 'string', 'max:50'],
            'filter.entity_table' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filter.entity_id' => ['sometimes', 'nullable', 'integer'],
            'filter.actor_id' => ['sometimes', 'nullable', 'integer'],
            'filter.from' => ['sometimes', 'nullable', 'date'],
            'filter.to' => ['sometimes', 'nullable', 'date'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
