<?php

namespace App\Services\Catalog;

use App\Models\ProductVariant;

/** Pure helpers for variants: uniqueness signature (BR-092), size label, display name, SKU base and EAN-13 barcodes. */
final class VariantSupport
{
    private const POSITION_SHORT = ['left' => 'L', 'right' => 'R', 'interior' => 'INT', 'pair' => 'PR', 'universal' => 'UNI'];

    private const POSITION_NAME = ['left' => 'LH', 'right' => 'RH', 'interior' => 'Interior', 'pair' => 'Pair (LH+RH)', 'universal' => 'Universal'];

    private const FEATURE_NAME = [
        'heated' => 'Heated', 'auto_fold' => 'Auto-fold', 'turn_signal' => 'Turn signal', 'blind_spot_sensor' => 'BSM', 'side_camera' => 'Camera',
        'memory_tilt' => 'Memory', 'auto_dimming' => 'Auto-dimming', 'puddle_light' => 'Puddle light',
    ];

    /** "180 × 110 mm" from dimensions unless the admin typed a label. */
    public static function sizeLabel(float|string|null $width, float|string|null $height, ?string $override): ?string
    {
        $override = trim((string) $override);
        if ($override !== '') {
            return $override;
        }
        if ($width === null || $width === '' || $height === null || $height === '') {
            return null;
        }

        return self::num($width).' × '.self::num($height).' mm';
    }

    private static function num(float|string $v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
    }

    /**
     * BR-092: sha1 over product, position, size, glass, tint, finish, adjustment and the feature flags.
     *
     * @param  array<string, mixed>  $a
     */
    public static function signature(array $a): string
    {
        $parts = [
            (int) $a['product_id'], (string) $a['position'], mb_strtolower(trim((string) ($a['size_label'] ?? ''))),
            (int) ($a['glass_type_id'] ?? 0), (int) ($a['tint_id'] ?? 0), (int) ($a['color_finish_id'] ?? 0), (string) ($a['adjustment'] ?? ''),
            (int) ! empty($a['is_glass_only']), (int) ! empty($a['cover_included']),
        ];
        foreach (ProductVariant::FEATURE_FLAGS as $flag) {
            $parts[] = (int) ! empty($a[$flag]);
        }
        $parts[] = (int) ($a['pin_count'] ?? 0);

        return sha1(implode('|', $parts));
    }

    /**
     * "RH · Electric · Heated · Convex · Black · 180 × 110 mm"
     *
     * @param  array<string, mixed>  $a
     */
    public static function variantName(array $a, ?string $glass, ?string $tint, ?string $finish): string
    {
        $bits = [self::POSITION_NAME[$a['position']] ?? (string) $a['position']];
        if (! empty($a['adjustment'])) {
            $bits[] = ucfirst((string) $a['adjustment']);
        }
        foreach (self::FEATURE_NAME as $flag => $label) {
            if (! empty($a[$flag])) {
                $bits[] = $label;
            }
        }
        if (! empty($a['is_glass_only'])) {
            $bits[] = 'Glass only';
        }
        foreach ([$glass, $tint, $finish] as $l) {
            if ($l !== null && $l !== '') {
                $bits[] = $l;
            }
        }
        if (! empty($a['size_label'])) {
            $bits[] = (string) $a['size_label'];
        }

        return implode(' · ', $bits);
    }

    /** SKU base like "SM-TOY-AXIO-R-180X110". */
    public static function skuBase(string $productCode, string $position, ?string $sizeLabel, ?float $width, ?float $height): string
    {
        $size = ($width && $height) ? self::num($width).'X'.self::num($height) : strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $sizeLabel));

        return strtoupper($productCode).'-'.(self::POSITION_SHORT[$position] ?? 'X').($size !== '' ? '-'.$size : '');
    }

    /** EAN-13 check digit for the first 12 digits. */
    public static function ean13CheckDigit(string $digits12): int
    {
        $sum = 0;
        foreach (str_split($digits12) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    /** Store-internal EAN-13: "200" + 9-digit variant id + check digit. Unique by construction. */
    public static function ean13ForVariant(int $variantId): string
    {
        $base = '200'.str_pad((string) $variantId, 9, '0', STR_PAD_LEFT);

        return $base.self::ean13CheckDigit($base);
    }

    public static function isValidEan13(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code) && self::ean13CheckDigit(substr($code, 0, 12)) === (int) $code[12];
    }
}
