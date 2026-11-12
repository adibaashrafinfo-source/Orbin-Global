<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $def = $this->route('field');
        $id = $def?->id;
        $req = $id ? 'sometimes' : 'required';
        $entity = $this->input('entity', $def?->entity);

        return [
            'entity' => [$id ? 'prohibited' : 'required', Rule::in(['product', 'variant', 'customer', 'supplier'])],
            'key' => [$id ? 'prohibited' : 'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('custom_field_defs', 'key')->where('entity', $entity)->whereNull('deleted_at')],
            'label_en' => [$req, 'string', 'max:255'],
            'label_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'field_type' => [$req, Rule::in(['text', 'long_text', 'number', 'date', 'select', 'multi_select', 'boolean', 'url'])],
            'options' => ['sometimes', 'nullable', 'array'],
            'is_required' => ['sometimes', 'boolean'],
            'show_on_pos_card' => ['sometimes', 'boolean'],
            'show_on_invoice' => ['sometimes', 'boolean'],
            'is_searchable' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $type = $this->input('field_type', $this->route('field')?->field_type);
            if (in_array($type, ['select', 'multi_select'], true) && ! $v->errors()->has('options')) {
                $opts = $this->has('options') ? $this->input('options') : $this->route('field')?->options;
                $ok = is_array($opts) && ($opts !== [] && (isset($opts['lookup']) || array_is_list($opts)));
                if (! $ok) {
                    $v->errors()->add('options', 'Select fields need options or a lookup list.');
                }
            }
        });
    }
}
