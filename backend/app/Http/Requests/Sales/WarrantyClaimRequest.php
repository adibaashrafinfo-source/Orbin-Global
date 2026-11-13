<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_item_id' => ['required', 'integer', Rule::exists('sale_items', 'id')],
            'issue' => ['required', 'string', 'min:3', 'max:2000'],
            'photo_attachment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('attachments', 'id')],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
