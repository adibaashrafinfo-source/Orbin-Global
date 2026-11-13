<?php

namespace App\Support;

use InvalidArgumentException;

/** PRD §7.3: "Taka Twelve Thousand Five Hundred Only", lakh and crore aware, with paisa. No floats. */
final class BdtToWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /** @param  string  $amount  money such as "12500", "12500.5" or "12345678.50" */
    public static function convert(string $amount): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', trim($amount))) {
            throw new InvalidArgumentException("Invalid amount: {$amount}");
        }
        [$whole, $frac] = array_pad(explode('.', trim($amount)), 2, '0');
        $paisa = (int) str_pad($frac, 2, '0');
        $taka = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        $text = $taka === '0' ? 'Zero' : self::integer($taka);
        if ($paisa > 0) {
            $text .= ' and '.self::below100($paisa).' Paisa';
        }

        return 'Taka '.$text.' Only';
    }

    /** Works on the digit string so values beyond a float's exact range stay exact. */
    private static function integer(string $digits): string
    {
        $crores = strlen($digits) > 7 ? substr($digits, 0, -7) : '0';
        $rest = str_pad(strlen($digits) > 7 ? substr($digits, -7) : $digits, 7, '0', STR_PAD_LEFT);
        $parts = [];
        if ($crores !== '0') {
            // More than 99 crore reads as "<n> Crore", where n itself uses lakh-style grouping, e.g. 1,23,45,67,890.
            $parts[] = (strlen($crores) > 2 ? self::integer($crores) : self::below100((int) $crores)).' Crore';
        }
        $lakh = (int) substr($rest, 0, 2);
        $thousand = (int) substr($rest, 2, 2);
        $hundreds = (int) substr($rest, 4, 3);
        if ($lakh > 0) {
            $parts[] = self::below100($lakh).' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = self::below100($thousand).' Thousand';
        }
        if ($hundreds > 0) {
            $parts[] = self::below1000($hundreds);
        }

        return implode(' ', $parts);
    }

    private static function below1000(int $n): string
    {
        $h = intdiv($n, 100);
        $r = $n % 100;

        return trim(($h > 0 ? self::ONES[$h].' Hundred' : '').($r > 0 ? ($h > 0 ? ' ' : '').self::below100($r) : ''));
    }

    private static function below100(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)].($n % 10 > 0 ? ' '.self::ONES[$n % 10] : ''));
    }
}
