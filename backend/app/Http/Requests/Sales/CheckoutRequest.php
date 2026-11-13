<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $money = ['numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];

        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'lines.*.qty' => ['required', ...['numeric', 'gt:0', 'max:999999', 'decimal:0,3']],
            'lines.*.unit_price' => ['required', ...$money],
            'lines.*.discount_type' => ['sometimes', Rule::in(['none', 'percent', 'amount'])],
            'lines.*.discount_value' => ['sometimes', ...$money],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines.*.override_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'vehicle' => ['sometimes', 'nullable', 'array'],
            'vehicle.customer_vehicle_id' => ['sometimes', 'nullable', 'integer'],
            'vehicle.reg_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'vehicle.make' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vehicle.model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vehicle.year' => ['sometimes', 'nullable', 'string', 'max:4'],
            'vehicle.color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sale_channel' => ['sometimes', Rule::in(['counter', 'phone', 'wholesale', 'online_order'])],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'cart_discount' => ['sometimes', 'array'],
            'cart_discount.type' => ['sometimes', Rule::in(['none', 'percent', 'amount'])],
            'cart_discount.value' => ['sometimes', ...$money],
            'delivery_charge' => ['sometimes', ...$money],
            'payments' => ['sometimes', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::in(['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer'])],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'payments.*.account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('financial_accounts', 'id')],
            'payments.*.tendered' => ['sometimes', 'nullable', ...$money],
            'payments.*.sender_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'payments.*.trx_id' => ['sometimes', 'nullable', 'string', 'max:60'],
            'payments.*.bank_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payments.*.bank_account_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'payments.*.bank_ref' => ['sometimes', 'nullable', 'string', 'max:80'],
            'payments.*.transfer_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'payments.*.attachment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('attachments', 'id')],
            'client_expected_grand_total' => ['required', ...$money],
            'approvals' => ['sometimes', 'array'],
            'approvals.manager_pin' => ['sometimes', 'nullable', 'string', 'max:12'],
            'approvals.reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'salesperson_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'quotation_id' => ['sometimes', 'nullable', 'integer', Rule::exists('quotations', 'id')],
        ];
    }
}
