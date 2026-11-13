<?php

namespace App\Services\Inventory;

use App\Services\SettingsService;
use App\Support\Dec;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything read-only about stock: the overview, alerts, the ledger, the stock card, valuation as of a date and aging.
 * Cost and value columns exist only when the caller may see them (AT-019): they are left out of the query, not hidden.
 */
class StockQueryService
{
    public const AGING_BUCKETS = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], '91-180' => [91, 180], '180+' => [181, PHP_INT_MAX]];

    public function __construct(private readonly SettingsService $settings) {}

    /** One row per variant (or per variant in one warehouse) with its stock and alert flags. */
    public function overview(array $f, bool $canCost): Builder
    {
        $wh = isset($f['warehouse_id']) && $f['warehouse_id'] !== '' ? (int) $f['warehouse_id'] : null;
        $deadDays = (int) $this->settings->get('dead_stock_days');
        $stock = DB::table('stock_levels')->whereNull('deleted_at')->when($wh, fn ($q) => $q->where('warehouse_id', $wh))->groupBy('variant_id')
            ->selectRaw('variant_id, SUM(qty_on_hand) as on_hand, SUM(qty_reserved) as reserved, SUM(qty_on_hand * avg_cost) as value_raw, MAX(last_movement_at) as last_move');
        $lastSale = DB::table('stock_movements')->where('movement_type', 'sale')->when($wh, fn ($q) => $q->where('warehouse_id', $wh))->groupBy('variant_id')->selectRaw('variant_id, MAX(occurred_at) as last_sale');
        $firstIn = DB::table('stock_movements')->where('qty_delta', '>', 0)->when($wh, fn ($q) => $q->where('warehouse_id', $wh))->groupBy('variant_id')->selectRaw('variant_id, MIN(occurred_at) as first_in');

        $inner = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoinSub($stock, 's', 's.variant_id', '=', 'v.id')
            ->leftJoinSub($lastSale, 'ls', 'ls.variant_id', '=', 'v.id')
            ->leftJoinSub($firstIn, 'fi', 'fi.variant_id', '=', 'v.id')
            ->whereNull('v.deleted_at')->whereNull('p.deleted_at')->where('v.is_bundle', false)
            ->selectRaw('v.id as variant_id, v.sku, v.variant_name, v.barcode, v.reorder_level, v.reorder_qty, v.max_stock, v.is_active, p.id as product_id, p.name_en as product_name, p.category_id,
                COALESCE(s.on_hand, 0) as on_hand, COALESCE(s.reserved, 0) as reserved, COALESCE(s.on_hand, 0) - COALESCE(s.reserved, 0) as available, s.last_move, ls.last_sale,
                CASE WHEN COALESCE(s.on_hand, 0) <= 0 THEN \'out\' WHEN v.reorder_level > 0 AND s.on_hand <= v.reorder_level THEN \'low\' WHEN v.max_stock IS NOT NULL AND s.on_hand > v.max_stock THEN \'over\' ELSE \'ok\' END as status,
                CASE WHEN COALESCE(s.on_hand, 0) > 0 AND COALESCE(ls.last_sale, fi.first_in) < ? THEN 1 ELSE 0 END as is_dead', [now()->subDays($deadDays)]);
        if ($canCost) {
            $inner->selectRaw('CASE WHEN COALESCE(s.on_hand, 0) > 0 THEN ROUND(s.value_raw / s.on_hand, 4) ELSE 0 END as avg_cost, ROUND(COALESCE(s.value_raw, 0), 2) as stock_value');
        }
        if ($search = mb_strtolower(trim((string) ($f['search'] ?? '')))) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $inner->where(fn ($w) => $w->where('v.search_text', 'like', $like)->orWhere('p.search_text', 'like', $like));
        }
        if (! empty($f['category_id'])) {
            $inner->whereIn('p.category_id', $this->categoryTree((int) $f['category_id']));
        }
        if (array_key_exists('active', $f) && $f['active'] !== '') {
            $inner->where('v.is_active', (bool) $f['active']);
        }
        // The status flags are computed columns, so they are filtered on the wrapped query.
        $q = DB::query()->fromSub($inner, 'o')->select('o.*');
        $status = $f['status'] ?? '';
        if (in_array($status, ['out', 'low', 'over', 'ok'], true)) {
            $q->where('status', $status);
        } elseif ($status === 'dead') {
            $q->where('is_dead', 1);
        } elseif ($status === 'in') {
            $q->where('on_hand', '>', 0);
        }

        return $q->orderBy('product_name')->orderBy('variant_name')->orderBy('variant_id');
    }

    /** The strip above the overview: SKUs in stock, units, value, low and out. @return array<string, string|int> */
    public function kpis(?int $warehouseId, bool $canCost): array
    {
        $rows = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoinSub(DB::table('stock_levels')->whereNull('deleted_at')->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->groupBy('variant_id')
                ->selectRaw('variant_id, SUM(qty_on_hand) as q, SUM(qty_on_hand * avg_cost) as val'), 's', 's.variant_id', '=', 'v.id')
            ->whereNull('v.deleted_at')->whereNull('p.deleted_at')->where('v.is_bundle', false)->where('v.is_active', true)
            ->selectRaw('SUM(CASE WHEN COALESCE(s.q, 0) > 0 THEN 1 ELSE 0 END) as skus, COALESCE(SUM(CASE WHEN s.q > 0 THEN s.q ELSE 0 END), 0) as units,
                COALESCE(SUM(s.val), 0) as val, SUM(CASE WHEN COALESCE(s.q, 0) <= 0 THEN 1 ELSE 0 END) as out_count,
                SUM(CASE WHEN COALESCE(s.q, 0) > 0 AND v.reorder_level > 0 AND s.q <= v.reorder_level THEN 1 ELSE 0 END) as low_count')->first();
        $out = ['skus' => (int) $rows->skus, 'units' => Dec::fmt(Dec::parse((string) $rows->units, Dec::QTY), Dec::QTY), 'low' => (int) $rows->low_count, 'out' => (int) $rows->out_count];
        if ($canCost) {
            $out['value'] = number_format((float) $rows->val, 2, '.', '');
        }

        return $out;
    }

    /** The ledger: every movement, newest first. */
    public function movements(array $f, bool $canCost): Builder
    {
        $q = DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.variant_id')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
            ->selectRaw('m.id, m.occurred_at, m.movement_type, m.qty_delta, m.balance_after, m.ref_table, m.ref_id, m.ref_no, m.reason, m.note, v.id as variant_id, v.sku, v.variant_name, w.id as warehouse_id, w.name as warehouse, u.full_name as user');
        if ($canCost) {
            $q->addSelect('m.unit_cost');
        }
        foreach (['variant_id', 'warehouse_id'] as $col) {
            if (! empty($f[$col])) {
                $q->where("m.{$col}", (int) $f[$col]);
            }
        }
        if (! empty($f['type'])) {
            $q->whereIn('m.movement_type', (array) $f['type']);
        }
        if (! empty($f['ref_no'])) {
            $q->where('m.ref_no', 'like', '%'.$f['ref_no'].'%');
        }
        if (! empty($f['from'])) {
            $q->where('m.occurred_at', '>=', Carbon::parse($f['from'], 'Asia/Dhaka')->startOfDay()->utc());
        }
        if (! empty($f['to'])) {
            $q->where('m.occurred_at', '<=', Carbon::parse($f['to'], 'Asia/Dhaka')->endOfDay()->utc());
        }

        return $q->orderByDesc('m.occurred_at')->orderByDesc('m.id');
    }

    /**
     * Stock card for one variant: oldest first with the running balance, plus the balance before the period.
     *
     * @return array{opening: string, rows: array<int, array<string, mixed>>, closing: string}
     */
    public function card(int $variantId, ?int $warehouseId, ?string $from, ?string $to, bool $canCost): array
    {
        $base = fn () => DB::table('stock_movements as m')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->where('m.variant_id', $variantId)->when($warehouseId, fn ($q) => $q->where('m.warehouse_id', $warehouseId));
        $fromAt = $from ? Carbon::parse($from, 'Asia/Dhaka')->startOfDay()->utc() : null;
        $toAt = $to ? Carbon::parse($to, 'Asia/Dhaka')->endOfDay()->utc() : null;
        $opening = $fromAt ? (string) $base()->where('m.occurred_at', '<', $fromAt)->sum('m.qty_delta') : '0';
        $rows = $base()->when($fromAt, fn ($q) => $q->where('m.occurred_at', '>=', $fromAt))->when($toAt, fn ($q) => $q->where('m.occurred_at', '<=', $toAt))
            ->orderBy('m.occurred_at')->orderBy('m.id')
            ->get(['m.id', 'm.occurred_at', 'm.movement_type', 'm.qty_delta', 'm.unit_cost', 'm.balance_after', 'm.ref_no', 'm.ref_table', 'm.ref_id', 'm.reason', 'm.warehouse_id', 'w.name as warehouse']);
        $running = Dec::parse($opening, Dec::QTY);
        $out = [];
        foreach ($rows as $r) {
            $running += Dec::parse((string) $r->qty_delta, Dec::QTY);
            $row = (array) $r;
            $row['running'] = Dec::fmt($running, Dec::QTY);
            if (! $canCost) {
                unset($row['unit_cost']);
            }
            $out[] = $row;
        }

        return ['opening' => Dec::fmt(Dec::parse($opening, Dec::QTY), Dec::QTY), 'rows' => $out, 'closing' => Dec::fmt($running, Dec::QTY)];
    }

    /**
     * Stock value as of the end of a day, rebuilt from the movements with the same costing rules posting uses (FR-409).
     * Needs cost permission; callers must check.
     *
     * @return array{as_of: string, total: string, rows: array<int, array<string, mixed>>}
     */
    public function valuation(string $asOf, ?int $warehouseId, ?int $categoryId): array
    {
        $end = Carbon::parse($asOf, 'Asia/Dhaka')->endOfDay()->utc();
        $q = DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('m.occurred_at', '<=', $end)->when($warehouseId, fn ($w) => $w->where('m.warehouse_id', $warehouseId))
            ->when($categoryId, fn ($w) => $w->whereIn('p.category_id', $this->categoryTree($categoryId)))
            ->orderBy('m.variant_id')->orderBy('m.warehouse_id')->orderBy('m.id')
            ->select(['m.variant_id', 'm.warehouse_id', 'm.movement_type', 'm.qty_delta', 'm.unit_cost', 'm.reason', 'v.sku', 'v.variant_name', 'p.name_en as product_name', 'p.category_id']);
        $rows = [];
        $total = 0;
        $state = null;
        $flush = function () use (&$state, &$rows, &$total): void {
            if ($state && $state['qty'] !== 0) {
                $value = Dec::value($state['qty'], $state['avg']);
                $total += $value;
                $rows[] = ['variant_id' => $state['variant_id'], 'warehouse_id' => $state['warehouse_id'], 'sku' => $state['sku'], 'variant_name' => $state['name'], 'product_name' => $state['product'],
                    'qty' => Dec::fmt($state['qty'], Dec::QTY), 'avg_cost' => Dec::fmt($state['avg'], Dec::COST), 'value' => Dec::fmt($value, Dec::MONEY)];
            }
        };
        foreach ($q->cursor() as $m) {
            if (! $state || $state['variant_id'] !== (int) $m->variant_id || $state['warehouse_id'] !== (int) $m->warehouse_id) {
                $flush();
                $state = ['variant_id' => (int) $m->variant_id, 'warehouse_id' => (int) $m->warehouse_id, 'qty' => 0, 'avg' => 0, 'sku' => $m->sku, 'name' => $m->variant_name, 'product' => $m->product_name];
            }
            $delta = Dec::parse((string) $m->qty_delta, Dec::QTY);
            $given = Dec::parse((string) $m->unit_cost, Dec::COST);
            [, $avg] = StockPostingService::costRule($m->movement_type, $delta, $state['qty'], $state['avg'], $given, 0, $m->movement_type === 'purchase_receipt' && $m->reason === StockPostingService::ARRIVAL_DAMAGE);
            $state['qty'] += $delta;
            $state['avg'] = $avg;
        }
        $flush();

        return ['as_of' => Carbon::parse($asOf)->toDateString(), 'total' => Dec::fmt($total, Dec::MONEY), 'rows' => $rows];
    }

    /**
     * How old the stock on the shelf is: units are matched to the newest receipts first (the oldest stock is what is left
     * longest), and counted in the age bucket of the receipt they came from. FR-411.
     *
     * @return array{buckets: array<string, array{qty: string, value: string}>, rows: array<int, array<string, mixed>>}
     */
    public function aging(?int $warehouseId, bool $canCost): array
    {
        $now = now();
        $levels = DB::table('stock_levels as l')->join('product_variants as v', 'v.id', '=', 'l.variant_id')->where('l.qty_on_hand', '>', 0)->whereNull('l.deleted_at')
            ->when($warehouseId, fn ($q) => $q->where('l.warehouse_id', $warehouseId))->orderBy('l.id')->get(['l.variant_id', 'l.warehouse_id', 'l.qty_on_hand', 'l.avg_cost', 'v.sku', 'v.variant_name']);
        $totals = array_fill_keys(array_keys(self::AGING_BUCKETS), ['qty' => 0, 'value' => 0]);
        $rows = [];
        foreach ($levels as $l) {
            $left = Dec::parse((string) $l->qty_on_hand, Dec::QTY);
            $avg = Dec::parse((string) $l->avg_cost, Dec::COST);
            $line = array_fill_keys(array_keys(self::AGING_BUCKETS), 0);
            $incoming = DB::table('stock_movements')->where(['variant_id' => $l->variant_id, 'warehouse_id' => $l->warehouse_id])->where('qty_delta', '>', 0)
                ->where(fn ($w) => $w->whereNull('reason')->orWhere('reason', '<>', StockPostingService::ARRIVAL_DAMAGE))->orderByDesc('occurred_at')->orderByDesc('id')->get(['qty_delta', 'occurred_at']);
            foreach ($incoming as $m) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, Dec::parse((string) $m->qty_delta, Dec::QTY));
                $age = (int) Carbon::parse($m->occurred_at)->diffInDays($now);
                $line[$this->bucket($age)] += $take;
                $left -= $take;
            }
            if ($left > 0) {
                $line['180+'] += $left; // stock with no receipt on record is treated as the oldest
            }
            foreach ($line as $b => $qty) {
                $totals[$b]['qty'] += $qty;
                $totals[$b]['value'] += Dec::value($qty, $avg);
            }
            $row = ['variant_id' => (int) $l->variant_id, 'warehouse_id' => (int) $l->warehouse_id, 'sku' => $l->sku, 'variant_name' => $l->variant_name, 'buckets' => array_map(fn ($q) => Dec::fmt($q, Dec::QTY), $line)];
            if ($canCost) {
                $row['avg_cost'] = Dec::fmt($avg, Dec::COST);
            }
            $rows[] = $row;
        }
        $buckets = [];
        foreach ($totals as $b => $t) {
            $buckets[$b] = ['qty' => Dec::fmt($t['qty'], Dec::QTY)] + ($canCost ? ['value' => Dec::fmt($t['value'], Dec::MONEY)] : []);
        }

        return ['buckets' => $buckets, 'rows' => $rows];
    }

    /** Breakage and damage by product, supplier, user and month, with the cost it represents (FR-404). */
    public function breakage(array $f): array
    {
        $q = DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
            ->leftJoin('goods_receipts as g', fn ($j) => $j->on('g.id', '=', 'm.ref_id')->where('m.ref_table', '=', 'goods_receipts'))
            ->leftJoin('stock_adjustment_items as ai', fn ($j) => $j->on('ai.adjustment_id', '=', 'm.ref_id')->on('ai.variant_id', '=', 'm.variant_id')->where('m.ref_table', '=', 'stock_adjustments')->whereNull('ai.deleted_at'))
            ->whereIn('m.movement_type', ['breakage', 'damage'])
            ->selectRaw('m.id, m.occurred_at, m.movement_type, -m.qty_delta as qty, m.unit_cost, ROUND(-m.qty_delta * m.unit_cost, 2) as cost_impact, m.ref_no, m.reason, v.id as variant_id, v.sku, v.variant_name, p.name_en as product_name,
                u.id as user_id, u.full_name as user, COALESCE(g.supplier_id, ai.supplier_id) as supplier_id, (SELECT name FROM suppliers s WHERE s.id = COALESCE(g.supplier_id, ai.supplier_id)) as supplier');
        if (! empty($f['from'])) {
            $q->where('m.occurred_at', '>=', Carbon::parse($f['from'], 'Asia/Dhaka')->startOfDay()->utc());
        }
        if (! empty($f['to'])) {
            $q->where('m.occurred_at', '<=', Carbon::parse($f['to'], 'Asia/Dhaka')->endOfDay()->utc());
        }
        foreach (['variant_id' => 'm.variant_id', 'user_id' => 'm.created_by'] as $key => $col) {
            if (! empty($f[$key])) {
                $q->where($col, (int) $f[$key]);
            }
        }
        if (! empty($f['supplier_id'])) {
            $q->whereRaw('COALESCE(g.supplier_id, ai.supplier_id) = ?', [(int) $f['supplier_id']]);
        }

        return $q->orderByDesc('m.occurred_at')->orderByDesc('m.id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function bucket(int $days): string
    {
        foreach (self::AGING_BUCKETS as $name => [$lo, $hi]) {
            if ($days >= $lo && $days <= $hi) {
                return $name;
            }
        }

        return '180+';
    }

    /** @return array<int, int> */
    public function categoryTree(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier) {
            $frontier = DB::table('categories')->whereIn('parent_id', $frontier)->whereNull('deleted_at')->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
