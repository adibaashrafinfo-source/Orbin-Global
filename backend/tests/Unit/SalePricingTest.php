<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\Sales\SalePricing;
use PHPUnit\Framework\TestCase;

/** BR-001, BR-003, BR-010: worked by hand. */
class SalePricingTest extends TestCase
{
    private function line(string $qty, string $price, string $dt = 'none', string $dv = '0', string $rate = '0'): array
    {
        return ['qty' => $qty, 'unit_price' => $price, 'discount_type' => $dt, 'discount_value' => $dv, 'tax_rate' => $rate];
    }

    public function test_plain_cart_without_discount_or_tax(): void
    {
        $r = SalePricing::compute([$this->line('2', '12500.00'), $this->line('1', '3000.00')], 'none', '0', '0', 'exclusive', 'none');
        $this->assertSame(2800000, $r['grand_total']);
        $this->assertSame(2800000, $r['subtotal']);
        $this->assertSame(0, $r['tax_total']);
    }

    public function test_line_percent_then_cart_amount_spread_in_proportion(): void
    {
        // A: 2 × 1000 = 2000, 10% off → 1800. B: 1 × 600 → 600. Left = 2400. Cart discount 240 → A 180, B 60.
        $r = SalePricing::compute([$this->line('2', '1000', 'percent', '10'), $this->line('1', '600')], 'amount', '240', '0', 'exclusive', 'none');
        $this->assertSame(18000, $r['lines'][0]['cart_share']);
        $this->assertSame(6000, $r['lines'][1]['cart_share']);
        $this->assertSame(162000, $r['lines'][0]['line_total']);
        $this->assertSame(54000, $r['lines'][1]['line_total']);
        $this->assertSame(216000, $r['grand_total']);
        $this->assertSame(20000, $r['line_discount_total']);
        $this->assertSame(24000, $r['cart_discount_amount']);
    }

    public function test_cart_discount_shares_always_add_up_to_the_discount(): void
    {
        // 100.00 split over three equal lines of 33.33: shares must sum to exactly 100.00.
        $r = SalePricing::compute([$this->line('1', '33.33'), $this->line('1', '33.33'), $this->line('1', '33.33')], 'amount', '10.00', '0', 'exclusive', 'none');
        $this->assertSame(1000, array_sum(array_column($r['lines'], 'cart_share')));
        $this->assertSame(9999 - 1000, $r['grand_total']);
    }

    public function test_vat_exclusive_is_added_on_the_taxable_amount(): void
    {
        // 1000 less 100 = 900, VAT 15% = 135 → 1035.
        $r = SalePricing::compute([$this->line('1', '1000', 'amount', '100', '15')], 'none', '0', '0', 'exclusive', 'none');
        $this->assertSame(13500, $r['tax_total']);
        $this->assertSame(90000, $r['taxable_amount']);
        $this->assertSame(103500, $r['grand_total']);
    }

    public function test_vat_inclusive_is_taken_out_of_the_price(): void
    {
        // 1150 includes 15%: tax = 1150 × 15 / 115 = 150, taxable 1000, total stays 1150.
        $r = SalePricing::compute([$this->line('1', '1150', 'none', '0', '15')], 'none', '0', '0', 'inclusive', 'none');
        $this->assertSame(15000, $r['tax_total']);
        $this->assertSame(100000, $r['taxable_amount']);
        $this->assertSame(115000, $r['grand_total']);
    }

    public function test_delivery_and_round_off_to_the_nearest_taka(): void
    {
        // 99.60 + 50.40 delivery = 150.00 exactly; then 100.49 + 0 rounds down by 0.49, 100.50 rounds up by 0.50.
        $r = SalePricing::compute([$this->line('1', '100.49')], 'none', '0', '0', 'exclusive', 'nearest_1');
        $this->assertSame(-49, $r['round_off']);
        $this->assertSame(10000, $r['grand_total']);
        $r = SalePricing::compute([$this->line('1', '100.50')], 'none', '0', '0', 'exclusive', 'nearest_1');
        $this->assertSame(50, $r['round_off']);
        $this->assertSame(10100, $r['grand_total']);
        $r = SalePricing::compute([$this->line('1', '99.60')], 'none', '0', '50.40', 'exclusive', 'nearest_1');
        $this->assertSame(0, $r['round_off']);
        $this->assertSame(15000, $r['grand_total']);
    }

    public function test_fractional_quantity_rounds_the_line_half_up(): void
    {
        // 0.5 × 12.35 = 6.175 → 6.18.
        $r = SalePricing::compute([$this->line('0.5', '12.35')], 'none', '0', '0', 'exclusive', 'none');
        $this->assertSame(618, $r['grand_total']);
    }

    public function test_a_discount_larger_than_the_amount_is_refused(): void
    {
        $this->expectException(ApiException::class);
        SalePricing::compute([$this->line('1', '100', 'amount', '150')], 'none', '0', '0', 'exclusive', 'none');
    }

    public function test_percent_over_one_hundred_is_refused(): void
    {
        $this->expectException(ApiException::class);
        SalePricing::compute([$this->line('1', '100', 'percent', '101')], 'none', '0', '0', 'exclusive', 'none');
    }
}
