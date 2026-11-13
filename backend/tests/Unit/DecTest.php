<?php

namespace Tests\Unit;

use App\Support\Dec;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class DecTest extends TestCase
{
    public function test_parse_and_format_round_trip(): void
    {
        $this->assertSame(12500, Dec::parse('12.5', 3));
        $this->assertSame('12.500', Dec::fmt(12500, 3));
        $this->assertSame(-150, Dec::parse('-1.5', 2));
        $this->assertSame('-1.50', Dec::fmt(-150, 2));
        $this->assertSame('0.0005', Dec::fmt(5, 4));
        $this->assertSame(1000, Dec::parse(1, 3));
        $this->assertSame(125, Dec::parse('1.2500', 2), 'trailing zeros beyond the scale are accepted');
    }

    public function test_extra_places_junk_and_huge_numbers_are_rejected(): void
    {
        foreach (['1.2345', '1e3', '', 'abc', '1,000'] as $bad) {
            try {
                Dec::parse($bad, 3);
                $this->fail("{$bad} should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(OverflowException::class);
        Dec::parse('99999999999999999999', 2);
    }

    public function test_division_rounds_half_away_from_zero(): void
    {
        $this->assertSame(3, Dec::div(5, 2));
        $this->assertSame(-3, Dec::div(-5, 2));
        $this->assertSame(2, Dec::div(4, 2));
        $this->assertSame(0, Dec::div(1, 3));
        $this->assertSame(1, Dec::div(2, 3));
    }

    public function test_multiplication_refuses_to_overflow(): void
    {
        $this->expectException(OverflowException::class);
        Dec::mul(PHP_INT_MAX, 2);
    }

    public function test_value_and_unit_cost_are_inverse_enough(): void
    {
        // 3.000 pcs at 1234.5678 = 3703.7034 BDT → 3703.70
        $this->assertSame(370370, Dec::value(3000, 12345678));
        // 3703.70 / 3 = 1234.5667 (rounded at 4 places)
        $this->assertSame(12345667, Dec::unitCost(370370, 3000));
    }

    public function test_allocation_sums_exactly_to_the_total(): void
    {
        // BDT 150.00 across 1:1:1 → 50.00 each; 100.00 across 1:1:1 → 33.34 / 33.33 / 33.33 (extra paisa to the first)
        $this->assertSame([5000, 5000, 5000], Dec::allocate(15000, [1, 1, 1]));
        $this->assertSame([3334, 3333, 3333], Dec::allocate(10000, [1, 1, 1]));
        $parts = Dec::allocate(1500000, [7000000, 3000000, 1]);
        $this->assertSame(1500000, array_sum($parts));
        $this->assertSame([0, 0], Dec::allocate(100, [0, 0]));
        $this->assertSame([0, 100], Dec::allocate(100, [0, 5]), 'a zero weight gets nothing');
    }
}
