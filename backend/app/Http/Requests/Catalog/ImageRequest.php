<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $upload = $this->route('image') === null;

        return [
            'file' => [$upload ? 'required' : 'prohibited', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp'],
            'variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'label' => ['sometimes', Rule::in(ProductImage::LABELS)],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
