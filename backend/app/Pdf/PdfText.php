<?php

namespace App\Pdf;

use App\Support\Money;
use Carbon\CarbonImmutable;

/** Escaping and formatting for the PDF templates. Bangla runs and the taka sign get the Bengali font so they shape correctly. */
final class PdfText
{
    private const BENGALI = '/[\x{0980}-\x{09FF}\x{200C}\x{200D}]+(?:[ \x{00A0}][\x{0980}-\x{09FF}\x{200C}\x{200D}]+)*/u';

    /** HTML-safe text; any Bangla script runs are wrapped in the Bengali font. */
    public static function e(mixed $value): string
    {
        $safe = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace(self::BENGALI, '<span class="bn">$0</span>', $safe);
    }

    /** "৳ 1,25,000.00" with lakh/crore grouping; the sign itself is drawn in the Bengali font. */
    public static function money(mixed $value, bool $symbol = true): string
    {
        $text = Money::of((string) ($value ?? '0'))->format(false);

        return ($symbol ? '<span class="bn">৳</span> ' : '').$text;
    }

    public static function qty(mixed $value): string
    {
        $v = rtrim(rtrim((string) $value, '0'), '.');

        return $v === '' ? '0' : $v;
    }

    public static function date(mixed $value, string $format = 'd M Y'): string
    {
        return $value === null ? '' : CarbonImmutable::parse($value)->setTimezone('Asia/Dhaka')->format($format);
    }

    /** Bangladeshi mobile numbers as 01XXX-XXXXXX; anything else untouched. */
    public static function phone(string $p): string
    {
        return preg_match('/^(01\d{3})(\d{6})$/', $p, $m) ? $m[1].'-'.$m[2] : $p;
    }

    public static function slug(string $name): string
    {
        $s = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-');

        return $s === '' ? 'Customer' : $s;
    }
}
