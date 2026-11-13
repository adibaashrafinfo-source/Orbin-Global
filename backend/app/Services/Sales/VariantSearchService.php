<?php

namespace App\Services\Sales;

use App\Models\OemCrossReference;
use App\Models\Warehouse;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * FR-702 to FR-704: POS search. Products are the cards; each card carries every fitting variant as a size chip with its live stock.
 * Cards are paged by product so a scroll never splits one product's sizes across two pages. Cost is never included.
 */
class VariantSearchService
{
    /**
     * @param  array{make_id?: int|null, model_id?: int|null, year?: int|null, position?: string|null, text?: string|null, only_in_stock?: bool, warehouse_id?: int|null, mirror_type?: string|null, brand_id?: int|null, quality_grade_id?: int|null}  $f
     * @return array{data: array<int, array<string, mixed>>, total: int, warehouse_id: int}
     */
    public function search(array $f, int $limit, int $offset): array
    {
        $warehouse = $this->warehouseId($f['warehouse_id'] ?? null);
        $variantIds = $this->matchingVariantIds($f, $warehouse);
        if ($variantIds === []) {
            return ['data' => [], 'total' => 0, 'warehouse_id' => $warehouse];
        }

        // Rank products: anything in stock first, then the cheapest retail price.
        $ranked = DB::table('product_variants as v')->leftJoin('stock_levels as s', fn ($j) => $j->on('s.variant_id', '=', 'v.id')->where('s.warehouse_id', $warehouse)->whereNull('s.deleted_at'))
            ->whereIn('v.id', $variantIds)->groupBy('v.product_id')
            ->selectRaw('v.product_id, MAX(COALESCE(s.qty_on_hand, 0) - COALESCE(s.qty_reserved, 0) > 0) as in_stock, MIN(v.retail_price) as from_price')
            ->orderByDesc('in_stock')->orderBy('from_price')->orderBy('v.product_id')->get();
        $total = $ranked->count();
        $page = $ranked->slice($offset, $limit)->pluck('product_id')->all();
        if ($page === []) {
            return ['data' => [], 'total' => $total, 'warehouse_id' => $warehouse];
        }

        $products = DB::table('products as p')->leftJoin('product_brands as b', 'b.id', '=', 'p.brand_id')->leftJoin('lookup_values as q', 'q.id', '=', 'p.quality_grade_id')
            ->whereIn('p.id', $page)->get(['p.id', 'p.name_en', 'p.name_bn', 'p.code', 'p.mirror_type', 'p.primary_image_url', 'p.warranty_months', 'b.name as brand', 'q.label_en as quality'])->keyBy('id');
        $variants = DB::table('product_variants as v')->leftJoin('stock_levels as s', fn ($j) => $j->on('s.variant_id', '=', 'v.id')->where('s.warehouse_id', $warehouse)->whereNull('s.deleted_at'))
            ->whereIn('v.id', $variantIds)->whereIn('v.product_id', $page)
            ->get(['v.id', 'v.product_id', 'v.sku', 'v.barcode', 'v.variant_name', 'v.position', 'v.size_label', 'v.adjustment', 'v.is_bundle', 'v.retail_price', 'v.wholesale_price', 'v.dealer_price', 'v.min_price', 'v.tax_rate_id',
                'v.heated', 'v.auto_fold', 'v.turn_signal', 'v.blind_spot_sensor', 'v.side_camera', 'v.memory_tilt', 'v.auto_dimming', 'v.puddle_light',
                DB::raw('COALESCE(s.qty_on_hand, 0) - COALESCE(s.qty_reserved, 0) as available')]);
        $bundleStock = $this->bundleAvailability($variants->where('is_bundle', 1)->pluck('id')->all(), $warehouse);
        $thumbs = DB::table('product_images')->whereIn('product_id', $page)->whereNull('deleted_at')->orderByDesc('is_primary')->orderBy('sort_order')->get(['product_id', 'variant_id', 'thumb_url', 'url']);

        $byProduct = [];
        foreach ($variants as $v) {
            $available = $v->is_bundle ? ($bundleStock[$v->id] ?? 0) : Dec::parse((string) $v->available, Dec::QTY);
            $byProduct[$v->product_id][] = [
                'id' => (int) $v->id, 'sku' => $v->sku, 'barcode' => $v->barcode, 'variant_name' => $v->variant_name, 'position' => $v->position,
                'size_label' => $v->size_label, 'adjustment' => $v->adjustment, 'is_bundle' => (bool) $v->is_bundle,
                'retail_price' => $v->retail_price, 'wholesale_price' => $v->wholesale_price, 'dealer_price' => $v->dealer_price, 'min_price' => $v->min_price,
                'available' => Dec::fmt(max($available, 0), Dec::QTY),
                'features' => array_values(array_filter(['heated', 'auto_fold', 'turn_signal', 'blind_spot_sensor', 'side_camera', 'memory_tilt', 'auto_dimming', 'puddle_light'], fn ($k) => (bool) $v->{$k})),
            ];
        }
        $cards = [];
        foreach ($page as $pid) {
            $p = $products[$pid] ?? null;
            if ($p === null || empty($byProduct[$pid])) {
                continue;
            }
            $list = $byProduct[$pid];
            usort($list, fn ($a, $b) => [-1 * (int) ((float) $a['available'] > 0), $a['size_label'], $a['id']] <=> [-1 * (int) ((float) $b['available'] > 0), $b['size_label'], $b['id']]);
            $img = $thumbs->firstWhere('product_id', $pid);
            $prices = array_map(fn ($x) => Dec::parse($x['retail_price'], Dec::MONEY), $list);
            $cards[] = [
                'product_id' => (int) $pid, 'name' => $p->name_en, 'name_bn' => $p->name_bn, 'code' => $p->code, 'mirror_type' => $p->mirror_type, 'brand' => $p->brand, 'quality' => $p->quality,
                'warranty_months' => (int) $p->warranty_months, 'image' => $img->thumb_url ?? $img->url ?? $p->primary_image_url,
                'price_from' => Dec::fmt(min($prices), Dec::MONEY), 'price_to' => Dec::fmt(max($prices), Dec::MONEY),
                'variants' => $list,
            ];
        }

        return ['data' => $cards, 'total' => $total, 'warehouse_id' => $warehouse];
    }

    /** The one variant for a scanned barcode, SKU or exact OEM number, or null (FR-703). */
    public function scan(string $code, ?int $warehouseId): ?array
    {
        $code = trim($code);
        $id = DB::table('product_variants')->whereNull('deleted_at')->where('is_active', true)
            ->where(fn ($w) => $w->where('barcode', $code)->orWhere('sku', $code)->orWhereRaw('JSON_CONTAINS(COALESCE(barcode_aliases, JSON_ARRAY()), JSON_QUOTE(?))', [$code]))->value('id');
        if ($id === null) {
            return null;
        }
        $hit = $this->search(['text' => null, 'warehouse_id' => $warehouseId, '_ids' => [$id]], 1, 0);
        foreach ($hit['data'] as $card) {
            foreach ($card['variants'] as $v) {
                if ($v['id'] === (int) $id) {
                    return ['product' => collect($card)->except('variants')->all(), 'variant' => $v];
                }
            }
        }

        return null;
    }

    /** @return array<int, int> */
    private function matchingVariantIds(array $f, int $warehouse): array
    {
        $q = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereNull('v.deleted_at')->whereNull('p.deleted_at')->where('v.is_active', true)->where('v.is_discontinued', false)->where('p.is_active', true);
        if (! empty($f['_ids'])) {
            $q->whereIn('v.id', $f['_ids']);
        }
        if (! empty($f['mirror_type'])) {
            $q->where('p.mirror_type', $f['mirror_type']);
        }
        if (! empty($f['brand_id'])) {
            $q->where('p.brand_id', (int) $f['brand_id']);
        }
        if (! empty($f['quality_grade_id'])) {
            $q->where('p.quality_grade_id', (int) $f['quality_grade_id']);
        }
        if (! empty($f['position'])) {
            // A pair or a universal mirror fits either side; an interior mirror is its own thing.
            $positions = $f['position'] === 'interior' ? ['interior', 'universal'] : [$f['position'], 'pair', 'universal'];
            $q->whereIn('v.position', $positions);
        }
        if (! empty($f['make_id']) || ! empty($f['model_id']) || ! empty($f['year'])) {
            $q->whereExists(function ($x) use ($f): void {
                $x->selectRaw('1')->from('vehicle_fitments as vf')->join('vehicle_models as vm', 'vm.id', '=', 'vf.vehicle_model_id')
                    ->whereColumn('vf.variant_id', 'v.id')->whereNull('vf.deleted_at');
                if (! empty($f['model_id'])) {
                    $x->where('vf.vehicle_model_id', (int) $f['model_id']);
                } elseif (! empty($f['make_id'])) {
                    $x->where('vm.make_id', (int) $f['make_id']);
                }
                if (! empty($f['year'])) {
                    $x->where('vf.year_from', '<=', (int) $f['year'])->where(fn ($y) => $y->whereNull('vf.year_to')->orWhere('vf.year_to', '>=', (int) $f['year']));
                }
            });
        }
        $text = mb_strtolower(trim((string) ($f['text'] ?? '')));
        if ($text !== '') {
            foreach (preg_split('/\s+/', $text) ?: [] as $token) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $token).'%';
                $norm = OemCrossReference::normalize($token);
                $q->where(fn ($w) => $w->where('v.search_text', 'like', $like)->orWhere('v.barcode', $token)
                    ->orWhereExists(fn ($x) => $x->selectRaw('1')->from('oem_cross_references as o')->whereColumn('o.variant_id', 'v.id')->whereNull('o.deleted_at')->where('o.number_normalized', 'like', '%'.$norm.'%')));
            }
        }
        if (! empty($f['only_in_stock'])) {
            $q->whereExists(fn ($x) => $x->selectRaw('1')->from('stock_levels as s')->whereColumn('s.variant_id', 'v.id')->where('s.warehouse_id', $warehouse)->whereNull('s.deleted_at')->whereRaw('s.qty_on_hand - s.qty_reserved > 0'));
        }

        return $q->orderBy('v.id')->limit(3000)->pluck('v.id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * A bundle has no stock of its own: it can be sold as often as its scarcest component allows.
     *
     * @param  array<int, int>  $bundleIds
     * @return array<int, int> bundle variant id => sellable units (milli)
     */
    private function bundleAvailability(array $bundleIds, int $warehouse): array
    {
        if ($bundleIds === []) {
            return [];
        }
        $rows = DB::table('variant_bundle_items as b')->leftJoin('stock_levels as s', fn ($j) => $j->on('s.variant_id', '=', 'b.component_variant_id')->where('s.warehouse_id', $warehouse)->whereNull('s.deleted_at'))
            ->whereIn('b.bundle_variant_id', $bundleIds)->whereNull('b.deleted_at')
            ->get(['b.bundle_variant_id', 'b.qty', DB::raw('COALESCE(s.qty_on_hand, 0) - COALESCE(s.qty_reserved, 0) as available')]);
        $out = [];
        foreach ($rows as $r) {
            $units = Dec::mul(intdiv(Dec::parse((string) $r->available, Dec::QTY), max(Dec::parse((string) $r->qty, Dec::QTY), 1)), 1000);
            $out[$r->bundle_variant_id] = isset($out[$r->bundle_variant_id]) ? min($out[$r->bundle_variant_id], $units) : $units;
        }

        return $out;
    }

    private function warehouseId(?int $requested): int
    {
        if ($requested) {
            return $requested;
        }

        return (int) (Warehouse::query()->where(['is_default' => true, 'is_sellable' => true])->value('id') ?? Warehouse::query()->where('is_sellable', true)->orderBy('id')->value('id'));
    }
}
