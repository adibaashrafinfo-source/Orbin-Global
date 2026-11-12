<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_and_prints_decimal_strings_without_floats(): void
    {
        $this->assertSame(1250000, Money::of('12500.00')->paisa);
        $this->assertSame(1250050, Money::of('12500.5')->paisa);
        $this->assertSame(-125, Money::of('-1.25')->paisa);
        $this->assertSame('12500.00', Money::of('12500')->toDecimal());
        $this->assertSame('-1.25', Money::of('-1.25')->toDecimal());
        $this->assertSame('0.30', Money::of('0.10')->add(Money::of('0.20'))->toDecimal());
    }

    public function test_rejects_malformed_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('12,500.00');
    }

    public function test_quantity_multiplication_rounds_half_up_at_paisa(): void
    {
        $this->assertSame('37.50', Money::of('12.50')->mulQty('3')->toDecimal());
        $this->assertSame('6.25', Money::of('12.50')->mulQty('0.5')->toDecimal());
        // 10.01 × 0.333 = 3.33333 → 3.33
        $this->assertSame('3.33', Money::of('10.01')->mulQty('0.333')->toDecimal());
        // 0.01 × 0.5 = 0.005 → 0.01 (half-up)
        $this->assertSame('0.01', Money::of('0.01')->mulQty('0.5')->toDecimal());
    }

    public function test_percent_rounds_half_up(): void
    {
        $this->assertSame('625.00', Money::of('12500')->percent('5')->toDecimal());
        $this->assertSame('1.25', Money::of('10')->percent('12.5')->toDecimal());
        // 0.01 × 50% = 0.005 → 0.01
        $this->assertSame('0.01', Money::of('0.01')->percent('50')->toDecimal());
    }

    public function test_formats_with_lakh_crore_grouping(): void
    {
        $this->assertSame('৳ 0.00', Money::zero()->format());
        $this->assertSame('৳ 1.00', Money::of('1')->format());
        $this->assertSame('৳ 100.00', Money::of('100')->format());
        $this->assertSame('৳ 1,000.00', Money::of('1000')->format());
        $this->assertSame('৳ 99,999.00', Money::of('99999')->format());
        $this->assertSame('৳ 1,00,000.00', Money::of('100000')->format());
        $this->assertSame('৳ 1,23,45,678.50', Money::of('12345678.50')->format());
        $this->assertSame('-৳ 12.50', Money::of('-12.5')->format());
        $this->assertSame('1,25,000.00', Money::of('125000')->format(false));
    }
}
