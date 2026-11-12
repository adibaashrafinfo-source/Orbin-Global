<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VariantResource;
use App\Models\OemCrossReference;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** FR-307: find variants by SKU, barcode, OEM number (any spelling), name or tag. Exact SKU / barcode / OEM matches come first. */
class VariantSearchController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50'], 'product_id' => ['sometimes', 'integer']]);
        $q = mb_strtolower(trim((string) $request->input('q')));
        $norm = OemCrossReference::normalize($q);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $query = ProductVariant::query()->with(['product', 'glassType', 'tint', 'colorFinish'])
            ->where(fn ($w) => $w->where('search_text', 'like', $like)->orWhereHas('crossReferences', fn ($x) => $x->where('number_normalized', 'like', '%'.$norm.'%')))
            ->orderByRaw('CASE WHEN LOWER(sku) = ? OR barcode = ? OR EXISTS (SELECT 1 FROM oem_cross_references x WHERE x.variant_id = product_variants.id AND x.deleted_at IS NULL AND x.number_normalized = ?) THEN 0 ELSE 1 END', [$q, $q, $norm])
            ->orderBy('variant_name')->limit((int) $request->input('limit', 20));
        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->input('product_id'));
        }

        return VariantResource::collection($query->get()->each(fn ($v) => $v->setRelation('images', collect())))->additional([]);
    }
}
