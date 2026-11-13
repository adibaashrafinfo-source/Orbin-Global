<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/**
 * Exact decimal arithmetic on scaled integers, for quantities (3 places), document money (2) and unit costs (4).
 * No floats and no bcmath (not every cPanel host has it). Every operation rounds half away from zero and
 * refuses to overflow a 64-bit integer instead of silently wrapping.
 */
final class Dec
{
    public const QTY = 3;

    public const MONEY = 2;

    public const COST = 4;

    /** "12.5" → 12500 at scale 3. Rejects more places than the scale, exponents and junk. */
    public static function parse(string|int $value, int $scale): int
    {
        if (is_int($value)) {
            return self::mul($value, self::pow10($scale));
        }
        $value = trim($value);
        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid decimal: {$value}");
        }
        $frac = $m[3] ?? '';
        if (strlen($frac) > $scale) {
            if (rtrim(substr($frac, $scale), '0') !== '') {
                throw new InvalidArgumentException("Too many decimal places in {$value} (max {$scale})");
            }
            $frac = substr($frac, 0, $scale);
        }
        $digits = ltrim($m[2].str_pad($frac, $scale, '0'), '0');
        if (strlen($digits) > 18) {
            throw new OverflowException("Decimal too large: {$value}");
        }
        $int = (int) ($digits === '' ? '0' : $digits);

        return ($m[1] ?? '') === '-' ? -$int : $int;
    }

    /** 12500 at scale 3 → "12.500". */
    public static function fmt(int $value, int $scale): string
    {
        $neg = $value < 0;
        $abs = $neg ? -$value : $value;
        $s = str_pad((string) $abs, $scale + 1, '0', STR_PAD_LEFT);
        $out = $scale === 0 ? $s : substr($s, 0, -$scale).'.'.substr($s, -$scale);

        return ($neg ? '-' : '').$out;
    }

    public static function pow10(int $n): int
    {
        $v = 1;
        for ($i = 0; $i < $n; $i++) {
            $v *= 10;
        }

        return $v;
    }

    public static function mul(int $a, int $b): int
    {
        if ($a !== 0 && $b !== 0 && abs($b) > intdiv(PHP_INT_MAX, abs($a))) {
            throw new OverflowException('Amount too large.');
        }

        return $a * $b;
    }

    /** num / den rounded half away from zero. */
    public static function div(int $num, int $den): int
    {
        if ($den === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }
        $neg = ($num < 0) !== ($den < 0);
        $n = abs($num);
        $d = abs($den);
        $q = intdiv($n, $d);
        if (($n % $d) * 2 >= $d) {
            $q++;
        }

        return $neg ? -$q : $q;
    }

    /** Change the scale of a scaled integer, rounding half away from zero when it shrinks. */
    public static function rescale(int $value, int $from, int $to): int
    {
        if ($to === $from) {
            return $value;
        }
        if ($to > $from) {
            return self::mul($value, self::pow10($to - $from));
        }

        return self::div($value, self::pow10($from - $to));
    }

    /** qty (scale 3) × unit cost (scale 4) → value rounded to money (scale 2). */
    public static function value(int $qtyMilli, int $cost4): int
    {
        return self::rescale(self::mul($qtyMilli, $cost4), self::QTY + self::COST, self::MONEY);
    }

    /** value (money, scale 2) ÷ qty (scale 3) → unit cost at scale 4. */
    public static function unitCost(int $valuePaisa, int $qtyMilli): int
    {
        // value/qty in BDT = (paisa/100) / (milli/1000) ; at scale 4 → paisa*1e4*1e3/100/milli = paisa*1e5/milli
        return self::div(self::mul($valuePaisa, 100000), $qtyMilli);
    }

    /**
     * Split $total across weights so the parts sum exactly to $total (largest remainder, ties to the earlier index).
     *
     * @param  array<int, int>  $weights  non-negative
     * @return array<int, int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $out = array_fill_keys(array_keys($weights), 0);
        if ($sum <= 0 || $total === 0) {
            return $out;
        }
        $remainders = [];
        $given = 0;
        foreach ($weights as $k => $w) {
            $exact = self::mul($total, $w);
            $out[$k] = intdiv($exact, $sum);
            $remainders[$k] = $exact % $sum;
            $given += $out[$k];
        }
        $left = $total - $given;
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
        foreach ($order as $k) {
            if ($left <= 0) {
                break;
            }
            if ($weights[$k] > 0) {
                $out[$k]++;
                $left--;
            }
        }

        return $out;
    }
}
