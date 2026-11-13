<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Support\Dec;

/**
 * Pure invoice arithmetic, no database. Everything is integer paisa (money) or milli-units (quantity), so the same cart
 * always gives the same totals (BR-001, BR-003, BR-010). The browser may preview with the same rules; the server decides.
 *
 * Order: line gross → line discount → cart discount spread over lines in proportion to what is left (BR-010) → tax per line
 * → delivery → optional round-off to the nearest taka stored as its own figure.
 */
final class SalePricing
{
    /**
     * @param  array<int, array{qty: string, unit_price: string, discount_type: string, discount_value: string, tax_rate: string}>  $lines
     * @return array{lines: array<int, array<string, int>>, subtotal: int, line_discount_total: int, cart_discount_amount: int, taxable_amount: int, tax_total: int, delivery_charge: int, round_off: int, grand_total: int}
     */
    public static function compute(array $lines, string $cartDiscountType, string $cartDiscountValue, string $delivery, string $vatMode, string $roundingMode): array
    {
        $rows = [];
        foreach ($lines as $i => $line) {
            $qty = Dec::parse($line['qty'], Dec::QTY);
            $price = Dec::parse($line['unit_price'], Dec::MONEY);
            if ($qty <= 0 || $price < 0) {
                throw new ApiException('VALIDATION', 422, '', ["lines.$i.qty" => ['errors.REQUIRED']]);
            }
            $gross = Dec::rescale(Dec::mul($qty, $price), Dec::QTY + Dec::MONEY, Dec::MONEY);
            $discount = self::discount($line['discount_type'], $line['discount_value'], $gross, "lines.$i.discount_value");
            $rows[$i] = ['qty' => $qty, 'unit_price' => $price, 'gross' => $gross, 'line_discount' => $discount, 'net' => $gross - $discount, 'rate' => Dec::parse($line['tax_rate'], Dec::MONEY)];
        }

        $netSum = array_sum(array_column($rows, 'net'));
        $cartDiscount = self::discount($cartDiscountType, $cartDiscountValue, $netSum, 'cart_discount.value');
        $shares = Dec::allocate($cartDiscount, array_map(fn ($r) => $r['net'], $rows));

        $inclusive = $vatMode === 'inclusive';
        $taxableTotal = 0;
        $taxTotal = 0;
        $payable = 0;
        foreach ($rows as $i => &$r) {
            $r['cart_share'] = $shares[$i];
            $base = $r['net'] - $r['cart_share'];
            $tax = $inclusive ? Dec::div(Dec::mul($base, $r['rate']), 10000 + $r['rate']) : Dec::div(Dec::mul($base, $r['rate']), 10000);
            $r['tax'] = $tax;
            $r['taxable'] = $inclusive ? $base - $tax : $base;
            $r['line_total'] = $inclusive ? $base : $base + $tax;
            $taxableTotal += $r['taxable'];
            $taxTotal += $tax;
            $payable += $r['line_total'];
        }
        unset($r);

        $deliveryPaisa = Dec::parse($delivery === '' ? '0' : $delivery, Dec::MONEY);
        if ($deliveryPaisa < 0) {
            throw new ApiException('VALIDATION', 422, '', ['delivery_charge' => ['errors.REQUIRED']]);
        }
        $before = $payable + $deliveryPaisa;
        $roundOff = $roundingMode === 'nearest_1' ? Dec::div($before, 100) * 100 - $before : 0;

        return [
            'lines' => $rows,
            'subtotal' => array_sum(array_column($rows, 'gross')),
            'line_discount_total' => array_sum(array_column($rows, 'line_discount')),
            'cart_discount_amount' => $cartDiscount,
            'taxable_amount' => $taxableTotal,
            'tax_total' => $taxTotal,
            'delivery_charge' => $deliveryPaisa,
            'round_off' => $roundOff,
            'grand_total' => $before + $roundOff,
        ];
    }

    private static function discount(string $type, string $value, int $base, string $field): int
    {
        if ($type === 'none' || $value === '' || Dec::parse($value, Dec::MONEY) === 0) {
            return 0;
        }
        $v = Dec::parse($value, Dec::MONEY);
        if ($v < 0) {
            throw new ApiException('VALIDATION', 422, '', [$field => ['errors.REQUIRED']]);
        }
        $amount = $type === 'percent' ? Dec::div(Dec::mul($base, $v), 10000) : $v;
        if ($type === 'percent' && $v > 10000 || $amount > $base) {
            throw new ApiException('VALIDATION', 422, '', [$field => ['errors.DISCOUNT_TOO_LARGE']]);
        }

        return $amount;
    }
}
