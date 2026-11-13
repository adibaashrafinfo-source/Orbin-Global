<?php

namespace Tests\Feature\Sales;

use App\Models\CashShift;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\CustomerService;
use App\Services\Sales\SaleService;
use App\Services\Sales\ShiftService;
use App\Support\Dec;
use Tests\Feature\Inventory\InventoryTestCase;

/** Helpers for POS tests: a complete company profile, an open shift, stocked variants and a ready-made checkout payload. */
abstract class SalesTestCase extends InventoryTestCase
{
    protected User $cashier;

    protected CashShift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        CompanyProfile::current()->forceFill([
            'legal_name' => 'Orbin Global', 'address_line1' => '12 Mirror Lane', 'city' => 'Dhaka', 'phones' => ['01711000000'], 'email' => 'hello@orbin.test',
            'invoice_terms' => 'Goods once sold are returnable within the window.',
        ])->save();
        $this->cashier = $this->userWithRole('cashier');
        $this->shift = app(ShiftService::class)->open($this->cashier, $this->account('cash')->id, '1000');
    }

    protected function account(string $type): FinancialAccount
    {
        return FinancialAccount::query()->where('account_type', $type)->orderBy('id')->firstOrFail();
    }

    /** A shift for someone other than the default cashier, so their sales are allowed. */
    protected function shiftFor(User $user): CashShift
    {
        return app(ShiftService::class)->open($user, $this->account('cash')->id, '0');
    }

    protected function walkIn(): Customer
    {
        return Customer::query()->where('is_walk_in', true)->firstOrFail();
    }

    /** @param  array<string, mixed>  $over */
    protected function customer(array $over = []): Customer
    {
        $this->actingAsRole('cashier');

        return app(CustomerService::class)->create(array_merge(['name' => 'Rahim Garage', 'phones' => ['017'.random_int(10000000, 99999999)], 'price_tier' => 'retail', 'credit_limit' => '0'], $over));
    }

    /**
     * Stocked variants (stock qty each at cost 600) priced 1000 retail. Returns ids.
     *
     * @return array<int, int>
     */
    protected function stocked(int $n = 1, string $qty = '10', array $variantOver = []): array
    {
        $ids = $this->variants($n, $variantOver);
        Product::query()->whereIn('id', ProductVariant::query()->whereIn('id', $ids)->pluck('product_id'))->update(['is_active' => true]);
        foreach ($ids as $id) {
            $this->stock($id, $qty, '600');
        }

        return $ids;
    }

    /**
     * A valid cash checkout payload for these (variant id => qty) lines at the listed price.
     *
     * @param  array<int, string>  $qtys  variant id => qty
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    protected function payload(array $qtys, array $over = []): array
    {
        $lines = [];
        $total = '0';
        foreach ($qtys as $id => $qty) {
            $lines[] = ['variant_id' => $id, 'qty' => $qty, 'unit_price' => '1000.00', 'discount_type' => 'none', 'discount_value' => '0'];
            $total = bcadd_money($total, bcmul_money('1000.00', $qty));
        }

        // A shallow merge on purpose: an override of lines or payments replaces them whole, and [] means none.
        return array_merge([
            'idempotency_key' => bin2hex(random_bytes(12)), 'lines' => $lines, 'customer_id' => $this->walkIn()->id, 'warehouse_id' => $this->showroom, 'sale_channel' => 'counter',
            'cart_discount' => ['type' => 'none', 'value' => '0'], 'delivery_charge' => '0', 'client_expected_grand_total' => $total,
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $over);
    }

    /** @param  array<string, mixed>  $payload */
    protected function checkout(array $payload, ?User $as = null): Sale
    {
        $this->actingAsUser($as ?? $this->cashier);

        return app(SaleService::class)->complete($as ?? $this->cashier, $payload);
    }
}

/** Money as integer paisa maths so test helpers need no bcmath and no floats. */
function bcadd_money(string $a, string $b): string
{
    return Dec::fmt(Dec::parse($a, 2) + Dec::parse($b, 2), 2);
}

function bcmul_money(string $price, string $qty): string
{
    return Dec::fmt(Dec::rescale(Dec::mul(Dec::parse($qty, 3), Dec::parse($price, 2)), 5, 2), 2);
}
