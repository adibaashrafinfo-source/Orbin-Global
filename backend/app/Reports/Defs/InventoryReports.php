<?php

namespace App\Reports\Defs;

use App\Reports\CarbonLocal;
use App\Reports\Col;
use App\Reports\Q;
use App\Reports\ReportContext;
use App\Reports\ReportDef;
use App\Reports\ReportFilters;
use App\Services\Inventory\ReorderService;
use App\Services\Inventory\StockQueryService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** Stock reports. They reuse the Phase 2 query services so a report and the matching screen give the same figures. Values need the cost permission. */
final class InventoryReports
{
    private const PERM = ['reports.view_all', 'reports.view_stock'];

    private const DOC = ['goods_receipts' => 'goods_receipts', 'sales' => 'sales', 'sale_returns' => 'sale_returns', 'stock_adjustments' => 'stock_adjustments', 'stock_transfers' => 'stock_transfers', 'stock_counts' => 'stock_counts', 'purchase_returns' => 'purchase_returns'];

    /** @return array<int, ReportDef> */
    public static function defs(): array
    {
        $buckets = array_map(fn (string $b) => new Col('b'.$b, $b.' days', 'qty', sum: true), array_keys(StockQueryService::AGING_BUCKETS));

        return [
            new ReportDef('stock-current', 'Current stock', 'inventory', self::PERM, [
                new Col('sku', 'SKU'), new Col('product', 'Product'), new Col('variant', 'Variant'), new Col('warehouse', 'Warehouse'), new Col('category', 'Category'), new Col('brand', 'Brand'),
                new Col('on_hand', 'On hand', 'qty', sum: true), new Col('available', 'Available', 'qty', sum: true), new Col('avg_cost', 'Average cost', 'cost', true), new Col('value', 'Value', 'money', true, true),
            ], static function (ReportFilters $f) {
                $q = DB::table('stock_levels as l')->join('product_variants as v', 'v.id', '=', 'l.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
                    ->join('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('product_brands as b', 'b.id', '=', 'p.brand_id')->whereNull('l.deleted_at')->whereNull('v.deleted_at')->where('l.qty_on_hand', '<>', 0)
                    ->when($f->warehouseId, fn ($qq) => $qq->where('l.warehouse_id', $f->warehouseId))->when($f->branchId, fn ($qq) => $qq->where('w.branch_id', $f->branchId))
                    ->orderBy('p.name_en')->orderBy('v.id')->orderBy('w.id')
                    ->selectRaw('v.id as variant_id, v.sku, p.name_en as product, COALESCE(v.variant_name, \'\') as variant, w.name as warehouse, c.name_en as category, COALESCE(b.name, \'\') as brand, l.qty_on_hand, l.qty_reserved, l.avg_cost, ROUND(l.qty_on_hand * l.avg_cost, 2) as value');
                foreach ($q->cursor() as $r) {
                    yield ['sku' => $r->sku, 'product' => $r->product, 'variant' => $r->variant, 'warehouse' => $r->warehouse, 'category' => $r->category, 'brand' => $r->brand, 'on_hand' => Q::qty($r->qty_on_hand),
                        'available' => Dec::fmt(Dec::parse((string) $r->qty_on_hand, Dec::QTY) - Dec::parse((string) $r->qty_reserved, Dec::QTY), Dec::QTY),
                        'avg_cost' => Dec::fmt(Dec::parse((string) $r->avg_cost, Dec::COST), Dec::COST), 'value' => Q::money($r->value), '_links' => ['sku' => ['table' => 'variants', 'id' => (int) $r->variant_id]]];
                }
            }, null, ['range' => 'none', 'warehouse' => true, 'branch' => true], needsCost: false),
            new ReportDef('stock-valuation', 'Stock valuation as of a date', 'inventory', self::PERM, [
                new Col('sku', 'SKU'), new Col('product', 'Product'), new Col('variant', 'Variant'), new Col('qty', 'Qty', 'qty', sum: true), new Col('avg_cost', 'Average cost', 'cost'), new Col('value', 'Value', 'money', sum: true),
            ], static function (ReportFilters $f) {
                $v = app(StockQueryService::class)->valuation($f->asOf, $f->warehouseId, null);
                foreach ($v['rows'] as $r) {
                    yield ['sku' => $r['sku'], 'product' => $r['product_name'], 'variant' => (string) $r['variant_name'], 'qty' => $r['qty'], 'avg_cost' => $r['avg_cost'], 'value' => $r['value']];
                }
            }, null, ['range' => 'asof', 'warehouse' => true], needsCost: true),
            new ReportDef('stock-low-out-over', 'Low, out and over stock', 'inventory', self::PERM, [
                new Col('sku', 'SKU'), new Col('product', 'Product'), new Col('status', 'Status'), new Col('on_hand', 'On hand', 'qty', sum: true), new Col('reorder_level', 'Reorder level', 'qty'), new Col('suggested', 'Suggested order', 'qty', sum: true),
            ], static function (ReportFilters $f, ReportContext $c) {
                foreach (app(StockQueryService::class)->overview(['warehouse_id' => $f->warehouseId, 'search' => $f->extra('search', '')], false)->cursor() as $r) {
                    if (! in_array($r->status, ['low', 'out', 'over'], true)) {
                        continue;
                    }
                    $onHand = Dec::parse((string) $r->on_hand, Dec::QTY);
                    $level = Dec::parse((string) $r->reorder_level, Dec::QTY);
                    $order = Dec::parse((string) $r->reorder_qty, Dec::QTY);
                    $suggest = $r->status === 'over' ? 0 : ($order > 0 ? $order : max($level - $onHand, 1000));
                    yield ['sku' => $r->sku, 'product' => $r->product_name.($r->variant_name ? ' · '.$r->variant_name : ''), 'status' => ucfirst((string) $r->status), 'on_hand' => Dec::fmt($onHand, Dec::QTY), 'reorder_level' => Dec::fmt($level, Dec::QTY),
                        'suggested' => Dec::fmt($suggest, Dec::QTY), '_links' => ['sku' => ['table' => 'variants', 'id' => (int) $r->variant_id]]];
                }
            }, null, ['range' => 'none', 'warehouse' => true, 'search' => true]),
            new ReportDef('stock-dead', 'Dead stock', 'inventory', self::PERM, [
                new Col('sku', 'SKU'), new Col('product', 'Product'), new Col('last_sale', 'Last sale', 'date'), new Col('days', 'Days since last sale', 'int'), new Col('on_hand', 'On hand', 'qty', sum: true), new Col('value', 'Value', 'money', true, true),
            ], static function (ReportFilters $f, ReportContext $c) {
                foreach (app(StockQueryService::class)->overview(['warehouse_id' => $f->warehouseId, 'status' => 'dead'], $c->canCost)->cursor() as $r) {
                    $since = $r->last_sale ?? $r->last_move;
                    yield ['sku' => $r->sku, 'product' => $r->product_name.($r->variant_name ? ' · '.$r->variant_name : ''), 'last_sale' => $r->last_sale ? substr((string) $r->last_sale, 0, 10) : '', 'days' => $since ? (int) now()->diffInDays($since, true) : 0,
                        'on_hand' => Q::qty($r->on_hand), 'value' => isset($r->stock_value) ? Q::money($r->stock_value) : '0.00', '_links' => ['sku' => ['table' => 'variants', 'id' => (int) $r->variant_id]]];
                }
            }, null, ['range' => 'none', 'warehouse' => true]),
            new ReportDef('stock-aging', 'Stock aging', 'inventory', self::PERM, array_merge([new Col('sku', 'SKU'), new Col('variant', 'Variant')], $buckets, [new Col('avg_cost', 'Average cost', 'cost', true)]), static function (ReportFilters $f, ReportContext $c) {
                foreach (app(StockQueryService::class)->aging($f->warehouseId, $c->canCost)['rows'] as $r) {
                    $row = ['sku' => $r['sku'], 'variant' => (string) $r['variant_name'], '_links' => ['sku' => ['table' => 'variants', 'id' => $r['variant_id']]]];
                    foreach ($r['buckets'] as $b => $qty) {
                        $row['b'.$b] = $qty;
                    }
                    if (isset($r['avg_cost'])) {
                        $row['avg_cost'] = $r['avg_cost'];
                    }
                    yield $row;
                }
            }, null, ['range' => 'none', 'warehouse' => true]),
            new ReportDef('stock-movement-summary', 'Stock movement summary', 'inventory', self::PERM, [
                new Col('type', 'Movement type'), new Col('in', 'In', 'qty', sum: true), new Col('out', 'Out', 'qty', sum: true), new Col('net', 'Net', 'qty', sum: true),
            ], static function (ReportFilters $f) {
                $q = DB::table('stock_movements as m')->where('m.occurred_at', '>=', $f->fromUtc())->where('m.occurred_at', '<', $f->toUtcExclusive())->when($f->warehouseId, fn ($qq) => $qq->where('m.warehouse_id', $f->warehouseId))
                    ->when($f->branchId, fn ($qq) => $qq->where('m.branch_id', $f->branchId))->groupBy('m.movement_type')->orderBy('m.movement_type')
                    ->selectRaw('m.movement_type, SUM(CASE WHEN m.qty_delta > 0 THEN m.qty_delta ELSE 0 END) as qin, SUM(CASE WHEN m.qty_delta < 0 THEN -m.qty_delta ELSE 0 END) as qout, SUM(m.qty_delta) as net');
                foreach ($q->cursor() as $r) {
                    yield ['type' => str_replace('_', ' ', (string) $r->movement_type), 'in' => Q::qty($r->qin), 'out' => Q::qty($r->qout), 'net' => Q::qty($r->net)];
                }
            }, null, ['compare' => true, 'warehouse' => true, 'branch' => true]),
            new ReportDef('stock-card', 'Stock card', 'inventory', self::PERM, [
                new Col('date', 'Date', 'datetime'), new Col('type', 'Type'), new Col('ref_no', 'Reference'), new Col('warehouse', 'Warehouse'), new Col('qty', 'Qty', 'qty'), new Col('balance', 'Balance', 'qty'), new Col('unit_cost', 'Unit cost', 'cost', true),
            ], static function (ReportFilters $f, ReportContext $c) {
                $id = (int) $f->extra('variant_id', 0);
                if ($id === 0) {
                    return;
                }
                $card = app(StockQueryService::class)->card($id, $f->warehouseId, $f->from, $f->to, $c->canCost);
                yield ['date' => '', 'type' => 'Opening balance', 'ref_no' => '', 'warehouse' => '', 'qty' => '', 'balance' => $card['opening'], 'unit_cost' => ''];
                foreach ($card['rows'] as $r) {
                    $table = self::DOC[$r['ref_table'] ?? ''] ?? null;
                    yield ['date' => substr(CarbonLocal::at((string) $r['occurred_at']), 0, 16), 'type' => str_replace('_', ' ', (string) $r['movement_type']), 'ref_no' => (string) ($r['ref_no'] ?? ''), 'warehouse' => (string) $r['warehouse'],
                        'qty' => Q::qty($r['qty_delta']), 'balance' => $r['running'], 'unit_cost' => isset($r['unit_cost']) ? Dec::fmt(Dec::parse((string) $r['unit_cost'], Dec::COST), Dec::COST) : ''] + ($table && $r['ref_id'] ? ['_links' => ['ref_no' => ['table' => $table, 'id' => (int) $r['ref_id']]]] : []);
                }
            }, null, ['variant' => true, 'warehouse' => true]),
            new ReportDef('breakage-report', 'Breakage and damage', 'inventory', self::PERM, [
                new Col('date', 'Date', 'datetime'), new Col('type', 'Type'), new Col('product', 'Product'), new Col('sku', 'SKU'), new Col('qty', 'Qty', 'qty', sum: true), new Col('cost', 'Cost', 'money', true, true),
                new Col('supplier', 'Supplier'), new Col('user', 'User'), new Col('reason', 'Reason'),
            ], static function (ReportFilters $f) {
                foreach (app(StockQueryService::class)->breakage(['from' => $f->from, 'to' => $f->to]) as $r) {
                    yield ['date' => substr(CarbonLocal::at((string) $r['occurred_at']), 0, 16), 'type' => (string) $r['movement_type'], 'product' => (string) $r['product_name'], 'sku' => (string) $r['sku'], 'qty' => Q::qty($r['qty']),
                        'cost' => Q::money($r['cost_impact']), 'supplier' => (string) ($r['supplier'] ?? ''), 'user' => (string) ($r['user'] ?? ''), 'reason' => (string) ($r['reason'] ?? '')];
                }
            }, null, ['compare' => true]),
            new ReportDef('adjustment-report', 'Stock adjustments', 'inventory', self::PERM, [
                new Col('adj_no', 'Adjustment'), new Col('date', 'Date', 'date'), new Col('warehouse', 'Warehouse'), new Col('reason', 'Reason'), new Col('user', 'By'), new Col('qty', 'Net qty', 'qty', sum: true), new Col('value', 'Value', 'money', true, true),
            ], static function (ReportFilters $f) {
                $items = DB::table('stock_adjustment_items')->whereNull('deleted_at')->groupBy('adjustment_id')->selectRaw('adjustment_id, SUM(qty_delta) as qty');
                $q = DB::table('stock_adjustments as a')->join('warehouses as w', 'w.id', '=', 'a.warehouse_id')->leftJoin('lookup_values as lv', 'lv.id', '=', 'a.reason_id')->leftJoin('users as u', 'u.id', '=', 'a.created_by')
                    ->leftJoinSub($items, 'it', 'it.adjustment_id', '=', 'a.id')->where('a.status', 'posted')->whereNull('a.deleted_at')->where('a.posted_at', '>=', $f->fromUtc())->where('a.posted_at', '<', $f->toUtcExclusive())
                    ->when($f->warehouseId, fn ($qq) => $qq->where('a.warehouse_id', $f->warehouseId))->when($f->branchId, fn ($qq) => $qq->where('a.branch_id', $f->branchId))->orderByDesc('a.posted_at')
                    ->selectRaw('a.id, a.adj_no, '.Q::day('a.posted_at').' as day, w.name as warehouse, COALESCE(lv.label_en, \'—\') as reason, u.full_name as user, COALESCE(it.qty, 0) as qty, a.total_value');
                foreach ($q->cursor() as $r) {
                    yield ['adj_no' => (string) $r->adj_no, 'date' => (string) $r->day, 'warehouse' => (string) $r->warehouse, 'reason' => (string) $r->reason, 'user' => (string) ($r->user ?? '—'), 'qty' => Q::qty($r->qty), 'value' => Q::money($r->total_value),
                        '_links' => ['adj_no' => ['table' => 'stock_adjustments', 'id' => (int) $r->id]]];
                }
            }, null, ['compare' => true, 'warehouse' => true, 'branch' => true]),
            new ReportDef('count-variance', 'Count variance', 'inventory', self::PERM, [
                new Col('count_no', 'Count'), new Col('date', 'Date', 'date'), new Col('sku', 'SKU'), new Col('system_qty', 'System qty', 'qty', sum: true), new Col('counted_qty', 'Counted qty', 'qty', sum: true),
                new Col('variance', 'Variance', 'qty', sum: true), new Col('value', 'Value', 'money', true, true),
            ], static function (ReportFilters $f) {
                $q = DB::table('stock_count_items as i')->join('stock_counts as c', 'c.id', '=', 'i.count_id')->join('product_variants as v', 'v.id', '=', 'i.variant_id')->where('c.status', 'posted')->whereNull('i.deleted_at')->whereNull('c.deleted_at')
                    ->where('c.posted_at', '>=', $f->fromUtc())->where('c.posted_at', '<', $f->toUtcExclusive())->whereNotNull('i.counted_qty')->whereRaw('i.counted_qty <> i.system_qty')
                    ->when($f->warehouseId, fn ($qq) => $qq->where('c.warehouse_id', $f->warehouseId))->when($f->branchId, fn ($qq) => $qq->where('c.branch_id', $f->branchId))->orderByDesc('c.posted_at')->orderBy('i.id')
                    ->selectRaw('c.id, c.count_no, '.Q::day('c.posted_at').' as day, v.sku, i.system_qty, i.counted_qty, (i.counted_qty - i.system_qty) as variance, ROUND((i.counted_qty - i.system_qty) * i.unit_cost, 2) as value');
                foreach ($q->cursor() as $r) {
                    yield ['count_no' => (string) $r->count_no, 'date' => (string) $r->day, 'sku' => (string) $r->sku, 'system_qty' => Q::qty($r->system_qty), 'counted_qty' => Q::qty($r->counted_qty), 'variance' => Q::qty($r->variance),
                        'value' => Q::money($r->value), '_links' => ['count_no' => ['table' => 'stock_counts', 'id' => (int) $r->id]]];
                }
            }, null, ['compare' => true, 'warehouse' => true, 'branch' => true]),
            new ReportDef('reorder-list', 'Reorder list by supplier', 'inventory', self::PERM, [
                new Col('supplier', 'Supplier'), new Col('sku', 'SKU'), new Col('product', 'Product'), new Col('on_hand', 'On hand', 'qty', sum: true), new Col('reorder_level', 'Reorder level', 'qty'),
                new Col('suggested_qty', 'Suggested order', 'qty', sum: true), new Col('last_cost', 'Last cost', 'money', true),
            ], static function (ReportFilters $f, ReportContext $c) {
                $rows = app(ReorderService::class)->suggestions($f->warehouseId, $c->canCost);
                usort($rows, fn ($a, $b) => [(string) ($a['supplier'] ?? 'zzz'), $a['sku']] <=> [(string) ($b['supplier'] ?? 'zzz'), $b['sku']]);
                foreach ($rows as $r) {
                    yield ['supplier' => (string) ($r['supplier'] ?? 'No supplier on record'), 'sku' => $r['sku'], 'product' => $r['product_name'].($r['variant_name'] ? ' · '.$r['variant_name'] : ''), 'on_hand' => $r['on_hand'],
                        'reorder_level' => $r['reorder_level'], 'suggested_qty' => $r['suggested_qty'], 'last_cost' => isset($r['last_cost']) ? Q::money($r['last_cost']) : '', '_links' => ['sku' => ['table' => 'variants', 'id' => $r['variant_id']]]];
                }
            }, null, ['range' => 'none', 'warehouse' => true]),
        ];
    }
}
