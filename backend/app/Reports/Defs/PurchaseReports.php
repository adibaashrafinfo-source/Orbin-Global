<?php

namespace App\Reports\Defs;

use App\Reports\Col;
use App\Reports\Q;
use App\Reports\ReportDef;
use App\Reports\ReportFilters;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** Purchasing reports: what was bought, what it really cost once freight and duty are spread over it, and what is still on order. */
final class PurchaseReports
{
    private const PERM = ['reports.view_all'];

    /** @return array<int, ReportDef> */
    public static function defs(): array
    {
        return [
            new ReportDef('purchase-summary', 'Purchases by supplier, product and month', 'purchase', self::PERM, [
                new Col('item', 'Item'), new Col('qty', 'Qty received', 'qty', sum: true), new Col('amount', 'Amount', 'money', sum: true),
            ], static function (ReportFilters $f) {
                $label = match ($f->extra('group', 'supplier')) {
                    'product' => 'p.name_en',
                    'month' => "DATE_FORMAT(g.received_date, '%Y-%m')",
                    default => 's.name',
                };
                $q = DB::table('goods_receipt_items as i')->join('goods_receipts as g', 'g.id', '=', 'i.grn_id')->join('suppliers as s', 's.id', '=', 'g.supplier_id')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
                    ->join('products as p', 'p.id', '=', 'v.product_id')->where('g.status', 'posted')->whereNull('g.deleted_at')->whereNull('i.deleted_at')->whereBetween('g.received_date', [$f->from, $f->to])
                    ->when($f->branchId, fn ($qq) => $qq->where('g.branch_id', $f->branchId))->groupByRaw($label)->orderByRaw($label)
                    ->selectRaw("$label as item, SUM(i.qty_received) as qty, SUM(i.line_total) as amount");
                foreach ($q->cursor() as $r) {
                    yield ['item' => (string) $r->item, 'qty' => Q::qty($r->qty), 'amount' => Q::money($r->amount)];
                }
            }, null, ['compare' => true, 'branch' => true, 'group' => ['supplier', 'product', 'month']], ['type' => 'bar', 'x' => 'item', 'y' => 'amount']),
            new ReportDef('landed-cost', 'Landed cost analysis', 'purchase', self::PERM, [
                new Col('grn', 'Receipt'), new Col('date', 'Date', 'date'), new Col('supplier', 'Supplier'), new Col('sku', 'SKU'), new Col('qty', 'Qty', 'qty', sum: true), new Col('unit_cost', 'Unit cost', 'cost'),
                new Col('allocated_extra', 'Freight and duty', 'money', sum: true), new Col('landed_cost', 'Landed unit cost', 'cost'), new Col('extra_pct', 'Extra %', 'percent'),
            ], static function (ReportFilters $f) {
                $q = DB::table('goods_receipt_items as i')->join('goods_receipts as g', 'g.id', '=', 'i.grn_id')->join('suppliers as s', 's.id', '=', 'g.supplier_id')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
                    ->where('g.status', 'posted')->whereNull('g.deleted_at')->whereNull('i.deleted_at')->whereBetween('g.received_date', [$f->from, $f->to])->when($f->branchId, fn ($qq) => $qq->where('g.branch_id', $f->branchId))
                    ->orderBy('g.received_date')->orderBy('g.id')->orderBy('i.id')
                    ->selectRaw('g.id, g.grn_no, g.received_date, s.name as supplier, v.sku, i.qty_received, i.unit_cost, i.allocated_extra, i.landed_unit_cost, ROUND(i.qty_received * i.unit_cost, 2) as base');
                foreach ($q->cursor() as $r) {
                    yield ['grn' => (string) $r->grn_no, 'date' => (string) $r->received_date, 'supplier' => (string) $r->supplier, 'sku' => (string) $r->sku, 'qty' => Q::qty($r->qty_received),
                        'unit_cost' => Dec::fmt(Dec::parse((string) $r->unit_cost, Dec::COST), Dec::COST), 'allocated_extra' => Q::money($r->allocated_extra), 'landed_cost' => Dec::fmt(Dec::parse((string) $r->landed_unit_cost, Dec::COST), Dec::COST),
                        'extra_pct' => Q::pct($r->allocated_extra, $r->base), '_links' => ['grn' => ['table' => 'goods_receipts', 'id' => (int) $r->id]]];
                }
            }, null, ['branch' => true]),
            new ReportDef('pending-po', 'Pending purchase orders', 'purchase', self::PERM, [
                new Col('po_no', 'Order'), new Col('date', 'Ordered on', 'date'), new Col('expected', 'Expected', 'date'), new Col('supplier', 'Supplier'), new Col('ordered', 'Ordered', 'qty', sum: true),
                new Col('received', 'Received', 'qty', sum: true), new Col('balance', 'Balance', 'qty', sum: true), new Col('status', 'Status'),
            ], static function (ReportFilters $f) {
                $items = DB::table('purchase_order_items')->whereNull('deleted_at')->groupBy('po_id')->selectRaw('po_id, SUM(qty_ordered) as ordered, SUM(qty_received) as received');
                $q = DB::table('purchase_orders as o')->join('suppliers as s', 's.id', '=', 'o.supplier_id')->leftJoinSub($items, 'it', 'it.po_id', '=', 'o.id')->whereIn('o.status', ['approved', 'partially_received'])->whereNull('o.deleted_at')
                    ->when($f->branchId, fn ($qq) => $qq->where('o.branch_id', $f->branchId))->orderBy('o.expected_date')->orderBy('o.id')
                    ->selectRaw('o.id, o.po_no, o.order_date, o.expected_date, s.name as supplier, o.status, COALESCE(it.ordered, 0) as ordered, COALESCE(it.received, 0) as received, COALESCE(it.ordered, 0) - COALESCE(it.received, 0) as balance');
                foreach ($q->cursor() as $r) {
                    yield ['po_no' => (string) $r->po_no, 'date' => (string) $r->order_date, 'expected' => (string) ($r->expected_date ?? ''), 'supplier' => (string) $r->supplier, 'ordered' => Q::qty($r->ordered), 'received' => Q::qty($r->received),
                        'balance' => Q::qty($r->balance), 'status' => str_replace('_', ' ', (string) $r->status), '_links' => ['po_no' => ['table' => 'purchase_orders', 'id' => (int) $r->id]]];
                }
            }, null, ['range' => 'none', 'branch' => true]),
            new ReportDef('supplier-price-trend', 'Supplier price trend', 'purchase', self::PERM, [
                new Col('sku', 'SKU'), new Col('supplier', 'Supplier'), new Col('date', 'Date', 'date'), new Col('grn', 'Receipt'), new Col('unit_cost', 'Unit cost', 'cost'), new Col('landed_cost', 'Landed unit cost', 'cost'),
            ], static function (ReportFilters $f) {
                $q = DB::table('goods_receipt_items as i')->join('goods_receipts as g', 'g.id', '=', 'i.grn_id')->join('suppliers as s', 's.id', '=', 'g.supplier_id')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
                    ->where('g.status', 'posted')->whereNull('g.deleted_at')->whereNull('i.deleted_at')->whereBetween('g.received_date', [$f->from, $f->to])
                    ->when($f->extra('variant_id'), fn ($qq, $id) => $qq->where('i.variant_id', (int) $id))->orderBy('v.sku')->orderBy('s.name')->orderBy('g.received_date')->orderBy('g.id')
                    ->selectRaw('g.id, g.grn_no, g.received_date, s.name as supplier, v.sku, i.unit_cost, i.landed_unit_cost');
                foreach ($q->cursor() as $r) {
                    yield ['sku' => (string) $r->sku, 'supplier' => (string) $r->supplier, 'date' => (string) $r->received_date, 'grn' => (string) $r->grn_no, 'unit_cost' => Dec::fmt(Dec::parse((string) $r->unit_cost, Dec::COST), Dec::COST),
                        'landed_cost' => Dec::fmt(Dec::parse((string) $r->landed_unit_cost, Dec::COST), Dec::COST), '_links' => ['grn' => ['table' => 'goods_receipts', 'id' => (int) $r->id]]];
                }
            }, null, ['variant' => true], ['type' => 'line', 'x' => 'date', 'y' => 'unit_cost']),
        ];
    }
}
