<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $update = $this->route('goodsReceipt') !== null;
        $req = $update ? 'sometimes' : 'required';

        return [
            'warehouse_id' => [$update ? 'prohibited' : 'required', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'supplier_id' => [$update ? 'prohibited' : 'required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'po_id' => [$update ? 'prohibited' : 'sometimes', 'nullable', 'integer', Rule::exists('purchase_orders', 'id')->whereNull('deleted_at')],
            'supplier_invoice_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'supplier_invoice_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'received_date' => [$req, 'date_format:Y-m-d'],
            'allocation_basis' => ['sometimes', Rule::in(['value', 'quantity'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...self::lineRules($update ? 'sometimes' : 'sometimes'),
        ];
    }

    /** Also used by the live landed-cost preview, which takes the same lines and extras. @return array<string, array<int, mixed>> */
    public static function lineRules(string $presence = 'sometimes'): array
    {
        return [
            'items' => [$presence, 'array', 'max:200'],
            'items.*.po_item_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.qty_received' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,3'],
            'items.*.qty_damaged' => ['sometimes', 'numeric', 'min:0', 'max:9999999', 'decimal:0,3'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'extra_costs' => ['sometimes', 'array', 'max:20'],
            'extra_costs.*.cost_type' => ['required', Rule::in(['freight', 'customs_duty', 'clearing_forwarding', 'local_transport', 'insurance', 'other'])],
            'extra_costs.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'extra_costs.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'extra_costs.*.paid_to' => ['sometimes', 'nullable', 'string', 'max:191'],
            'extra_costs.*.account_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
