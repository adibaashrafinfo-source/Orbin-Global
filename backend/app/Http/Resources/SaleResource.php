<?php

namespace App\Http\Resources;

use App\Models\Sale;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invoice as the screens and the PDF see it. Unit cost (COGS) is left out of the response unless the caller holds
 * products.view_cost: the field is absent, not hidden in the UI (AT-019).
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canCost = $user && app(PermissionService::class)->userHas($user, 'products.view_cost');

        return [
            ...$this->only(['id', 'invoice_no', 'verify_token', 'warehouse_id', 'shift_id', 'customer_id', 'customer_snapshot', 'vehicle_snapshot', 'sale_channel', 'status', 'due_date', 'vat_mode_snapshot',
                'subtotal', 'line_discount_total', 'cart_discount_type', 'cart_discount_value', 'cart_discount_amount', 'taxable_amount', 'tax_total', 'delivery_charge', 'round_off', 'grand_total', 'paid_total',
                'due_total', 'change_given', 'payment_status', 'salesperson_id', 'cashier_id', 'note', 'held_name', 'reprint_count', 'void_reason', 'voided_by']),
            'sale_date' => $this->sale_date?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'has_pdf' => (bool) $this->pdf_path,
            'cashier' => $this->whenLoaded('cashier', fn () => $this->cashier?->full_name),
            'salesperson' => $this->whenLoaded('salesperson', fn () => $this->salesperson?->full_name),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($i) use ($canCost) {
                $row = $i->only(['id', 'line_no', 'variant_id', 'product_name_snapshot', 'sku_snapshot', 'spec_snapshot', 'qty', 'qty_returned', 'unit_price', 'price_tier_used', 'discount_type',
                    'discount_value', 'discount_amount', 'cart_discount_share', 'tax_rate_percent', 'tax_amount', 'line_total', 'warranty_months', 'bundle_parent_line', 'note', 'price_override_by', 'price_override_reason']);
                $row['warranty_until'] = $i->warranty_until?->toDateString();
                if ($canCost) {
                    $row['unit_cost'] = $i->unit_cost;
                }

                return $row;
            })->values()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                ...$p->only(['id', 'method', 'amount', 'tendered', 'change_amount', 'account_id', 'sender_number', 'trx_id', 'bank_name', 'bank_account_no', 'bank_ref', 'verification']),
                'transfer_date' => $p->transfer_date?->toDateString(), 'received_at' => $p->received_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
