<?php

namespace App\Services\Catalog;

use App\Models\OemCrossReference;
use App\Models\Product;
use App\Models\ProductVariant;

/** Maintained lowercase `search_text` columns (NFR-001): one LIKE scan covers names, SKU, barcode, OEM numbers, tags and brand. */
final class SearchText
{
    public static function product(Product $p): string
    {
        $p->loadMissing('brand');

        return self::join([$p->name_en, $p->name_bn, $p->code, $p->brand?->name, implode(' ', $p->tags ?? [])]);
    }

    public static function variant(ProductVariant $v): string
    {
        $v->loadMissing(['product.brand', 'crossReferences']);
        $p = $v->product;
        $oem = [];
        foreach ($v->crossReferences as $x) {
            /** @var OemCrossReference $x */
            $oem[] = $x->number;
            $oem[] = $x->number_normalized;
        }

        return self::join([
            $p->name_en, $p->name_bn, $p->code, $p->brand?->name, implode(' ', $p->tags ?? []), $v->sku, $v->barcode,
            implode(' ', $v->barcode_aliases ?? []), $v->variant_name, $v->size_label, $v->supplier_part_no, implode(' ', $oem),
        ]);
    }

    /** @param array<int, string|null> $parts */
    private static function join(array $parts): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, fn ($x) => $x !== null && $x !== '')))));
    }
}
