<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $money = ['numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];

        return [
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')],
            'vehicle' => ['sometimes', 'nullable', 'array'],
            'vehicle.customer_vehicle_id' => ['sometimes', 'nullable', 'integer'],
            'vehicle.reg_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'vehicle.make' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vehicle.model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vehicle.year' => ['sometimes', 'nullable', 'string', 'max:4'],
            'vehicle.color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'lines.*.qty' => ['required', ...['numeric', 'gt:0', 'max:999999', 'decimal:0,3']],
            'lines.*.unit_price' => ['sometimes', ...$money],
            'lines.*.discount_type' => ['sometimes', Rule::in(['none', 'percent', 'amount'])],
            'lines.*.discount_value' => ['sometimes', ...$money],
            'delivery_charge' => ['sometimes', ...$money],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
