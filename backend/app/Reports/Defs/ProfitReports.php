<?php

namespace App\Reports\Defs;

use App\Reports\Col;
use App\Reports\Q;
use App\Reports\ReportContext;
use App\Reports\ReportDef;
use App\Reports\ReportFilters;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gross profit = Σ over invoice lines of (revenue − cost), net of returns and without VAT (see Q). The dashboard's profit is the
 * total of "Gross profit by invoice", and "Profit and loss" starts from the same figure, so they cannot disagree.
 */
final class ProfitReports
{
    private const PERM = ['reports.view_profit'];

    /** @return array<int, ReportDef> */
    public static function defs(): array
    {
        return [self::byInvoice(), self::byProduct(), self::profitLoss()];
    }

    private static function margin(): \Closure
    {
        return static fn (array $t): array => ['margin' => Q::pct($t['profit'], $t['revenue'])];
    }

    private static function byInvoice(): ReportDef
    {
        return new ReportDef('profit-by-invoice', 'Gross profit by invoice', 'profit', self::PERM, [
            new Col('invoice', 'Invoice'), new Col('date', 'Date', 'date'), new Col('customer', 'Customer'), new Col('revenue', 'Revenue (ex-VAT)', 'money', sum: true),
            new Col('cogs', 'Cost of goods', 'money', true, true), new Col('profit', 'Profit', 'money', true, true), new Col('margin', 'Margin %', 'percent', true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $q = Q::items($f, $c)->groupBy('s.id', 's.invoice_no', 's.sale_date', 's.customer_snapshot')->orderBy('s.sale_date')->orderBy('s.id')
                ->selectRaw('s.id, s.invoice_no, '.Q::day('s.sale_date').' as day, s.customer_snapshot, SUM('.Q::REV.') as revenue, SUM('.Q::COGS.') as cogs');
            foreach ($q->cursor() as $r) {
                $profit = Dec::fmt(Dec::parse((string) $r->revenue, Dec::MONEY) - Dec::parse((string) $r->cogs, Dec::MONEY), Dec::MONEY);
                yield ['invoice' => (string) $r->invoice_no, 'date' => (string) $r->day, 'customer' => (string) (json_decode((string) $r->customer_snapshot, true)['name'] ?? ''), 'revenue' => Q::money($r->revenue),
                    'cogs' => Q::money($r->cogs), 'profit' => $profit, 'margin' => Q::pct($profit, $r->revenue), '_links' => ['invoice' => ['table' => 'sales', 'id' => (int) $r->id]]];
            }
        }, self::margin(), ['compare' => true, 'branch' => true], needsCost: true);
    }

    private static function byProduct(): ReportDef
    {
        return new ReportDef('profit-by-product', 'Gross profit by product and category', 'profit', self::PERM, [
            new Col('item', 'Item'), new Col('qty', 'Qty', 'qty', sum: true), new Col('revenue', 'Revenue (ex-VAT)', 'money', sum: true), new Col('cogs', 'Cost of goods', 'money', true, true),
            new Col('profit', 'Profit', 'money', true, true), new Col('margin', 'Margin %', 'percent', true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $label = $f->extra('group', 'product') === 'category' ? 'c.name_en' : 'p.name_en';
            $q = Q::items($f, $c)->join('product_variants as v', 'v.id', '=', 'i.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')->join('categories as c', 'c.id', '=', 'p.category_id')
                ->groupByRaw($label)->orderByRaw('SUM('.Q::REV.') - SUM('.Q::COGS.') DESC')->selectRaw("$label as item, SUM(".Q::UNITS.') as qty, SUM('.Q::REV.') as revenue, SUM('.Q::COGS.') as cogs');
            foreach ($q->cursor() as $r) {
                $profit = Dec::fmt(Dec::parse((string) $r->revenue, Dec::MONEY) - Dec::parse((string) $r->cogs, Dec::MONEY), Dec::MONEY);
                yield ['item' => (string) $r->item, 'qty' => Q::qty($r->qty), 'revenue' => Q::money($r->revenue), 'cogs' => Q::money($r->cogs), 'profit' => $profit, 'margin' => Q::pct($profit, $r->revenue)];
            }
        }, self::margin(), ['compare' => true, 'branch' => true, 'group' => ['product', 'category']], ['type' => 'bar', 'x' => 'item', 'y' => 'profit'], needsCost: true);
    }

    private static function profitLoss(): ReportDef
    {
        return new ReportDef('profit-loss', 'Profit and loss', 'profit', self::PERM, [
            new Col('period', 'Month'), new Col('revenue', 'Revenue (ex-VAT)', 'money', sum: true), new Col('cogs', 'Cost of goods', 'money', true, true), new Col('gross_profit', 'Gross profit', 'money', true, true),
            new Col('breakage', 'Breakage and damage', 'money', true, true), new Col('expenses', 'Expenses', 'money', true, true), new Col('net_profit', 'Net profit', 'money', true, true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $sales = Q::items($f, $c)->groupByRaw(Q::month('s.sale_date'))->selectRaw(Q::month('s.sale_date').' as m, SUM('.Q::REV.') as revenue, SUM('.Q::COGS.') as cogs')->get()->keyBy('m');
            $lost = DB::table('stock_movements as m')->whereIn('m.movement_type', ['breakage', 'damage'])->where('m.occurred_at', '>=', $f->fromUtc())->where('m.occurred_at', '<', $f->toUtcExclusive())
                ->when($f->branchId, fn ($q) => $q->where('m.branch_id', $f->branchId))->groupByRaw(Q::month('m.occurred_at'))
                ->selectRaw(Q::month('m.occurred_at').' as m, SUM(ROUND(-m.qty_delta * m.unit_cost, 2)) as lost')->pluck('lost', 'm');
            $spent = DB::table('expenses')->where('status', 'posted')->whereNull('deleted_at')->whereBetween('expense_date', [$f->from, $f->to])->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId))
                ->groupByRaw("DATE_FORMAT(expense_date, '%Y-%m')")->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as m, SUM(amount) as spent")->pluck('spent', 'm');
            for ($d = CarbonImmutable::parse($f->from)->startOfMonth(); $d->lte(CarbonImmutable::parse($f->to)); $d = $d->addMonth()) {
                $m = $d->format('Y-m');
                $rev = Dec::parse((string) ($sales[$m]->revenue ?? '0'), Dec::MONEY);
                $cogs = Dec::parse((string) ($sales[$m]->cogs ?? '0'), Dec::MONEY);
                $gross = $rev - $cogs;
                $loss = Dec::parse((string) ($lost[$m] ?? '0'), Dec::MONEY);
                $exp = Dec::parse((string) ($spent[$m] ?? '0'), Dec::MONEY);
                yield ['period' => $m, 'revenue' => Dec::fmt($rev, Dec::MONEY), 'cogs' => Dec::fmt($cogs, Dec::MONEY), 'gross_profit' => Dec::fmt($gross, Dec::MONEY), 'breakage' => Dec::fmt($loss, Dec::MONEY),
                    'expenses' => Dec::fmt($exp, Dec::MONEY), 'net_profit' => Dec::fmt($gross - $loss - $exp, Dec::MONEY)];
            }
        }, null, ['compare' => true, 'branch' => true], ['type' => 'bar', 'x' => 'period', 'y' => 'net_profit'], needsCost: true);
    }
}
