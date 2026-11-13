<?php

namespace App\Reports\Defs;

use App\Reports\Col;
use App\Reports\Q;
use App\Reports\ReportContext;
use App\Reports\ReportDef;
use App\Reports\ReportFilters;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** The sales domain of the catalog. Invoices count as issued; item figures are net of returns (see Q). */
final class SalesReports
{
    private const OWN = ['reports.view_all', 'reports.view_own'];

    private const ALL = ['reports.view_all'];

    private const DAYS = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    private static function local(string $col): string
    {
        return "DATE_ADD($col, INTERVAL 6 HOUR)";
    }

    /** @return array<int, ReportDef> */
    public static function defs(): array
    {
        return [
            self::summary(), self::byProduct(), self::byBrand(), self::byVehicle(), self::byCashier(), self::byCustomer(),
            self::byPaymentMethod(), self::hourly(), self::discounts(), self::returns(), self::voids(),
        ];
    }

    private static function summary(): ReportDef
    {
        return new ReportDef('sales-summary', 'Sales summary', 'sales', self::OWN, [
            new Col('period', 'Period'), new Col('invoices', 'Invoices', 'int', sum: true), new Col('gross', 'Gross', 'money', sum: true), new Col('discount', 'Discount', 'money', sum: true),
            new Col('tax', 'VAT', 'money', sum: true), new Col('delivery', 'Delivery', 'money', sum: true), new Col('round_off', 'Round-off', 'money', sum: true),
            new Col('total', 'Total', 'money', sum: true), new Col('paid', 'Paid', 'money', sum: true), new Col('due', 'Due', 'money', sum: true), new Col('avg_ticket', 'Average ticket', 'money'),
        ], static function (ReportFilters $f, ReportContext $c) {
            $g = $f->extra('group', 'day') === 'month' ? Q::month('s.sale_date') : Q::day('s.sale_date');
            $q = Q::invoices($f, $c)->groupByRaw($g)->orderByRaw($g)->selectRaw("$g as period, COUNT(*) as n, SUM(s.subtotal) as gross, SUM(s.line_discount_total + s.cart_discount_amount) as discount, SUM(s.tax_total) as tax,
                SUM(s.delivery_charge) as delivery, SUM(s.round_off) as round_off, SUM(s.grand_total) as total, SUM(s.paid_total) as paid, SUM(s.due_total) as due");
            foreach ($q->cursor() as $r) {
                yield ['period' => (string) $r->period, 'invoices' => (int) $r->n, 'gross' => Q::money($r->gross), 'discount' => Q::money($r->discount), 'tax' => Q::money($r->tax), 'delivery' => Q::money($r->delivery),
                    'round_off' => Q::money($r->round_off), 'total' => Q::money($r->total), 'paid' => Q::money($r->paid), 'due' => Q::money($r->due),
                    'avg_ticket' => Dec::fmt($r->n > 0 ? Dec::div(Dec::parse((string) $r->total, Dec::MONEY), (int) $r->n) : 0, Dec::MONEY)];
            }
        }, static fn (array $t) => ['avg_ticket' => Dec::fmt((int) $t['invoices'] > 0 ? Dec::div(Dec::parse($t['total'], Dec::MONEY), (int) $t['invoices']) : 0, Dec::MONEY)],
            ['compare' => true, 'branch' => true, 'group' => ['day', 'month']], ['type' => 'line', 'x' => 'period', 'y' => 'total'], ownable: true);
    }

    private static function byProduct(): ReportDef
    {
        return new ReportDef('sales-by-product', 'Sales by product, variant and size', 'sales', self::OWN, [
            new Col('item', 'Item'), new Col('sku', 'SKU'), new Col('qty', 'Qty', 'qty', sum: true), new Col('revenue', 'Revenue (ex-VAT)', 'money', sum: true),
            new Col('cogs', 'Cost of goods', 'money', true, true), new Col('profit', 'Profit', 'money', true, true), new Col('margin', 'Margin %', 'percent', true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $by = $f->extra('group', 'product');
            [$key, $label, $sku] = match ($by) {
                'variant' => ['v.id', "CONCAT(p.name_en, ' · ', COALESCE(v.variant_name, v.sku))", 'MAX(v.sku)'],
                'size_label' => ["COALESCE(v.size_label, '')", "COALESCE(NULLIF(v.size_label, ''), 'No size')", "''"],
                default => ['p.id', 'p.name_en', "''"],
            };
            $q = Q::items($f, $c)->join('product_variants as v', 'v.id', '=', 'i.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                ->groupByRaw($key.', '.$label)->orderByRaw('SUM('.Q::REV.') DESC')
                ->selectRaw("$label as item, $sku as sku, MAX(v.id) as variant_id, SUM(".Q::UNITS.') as qty, SUM('.Q::REV.') as revenue, SUM('.Q::COGS.') as cogs');
            foreach ($q->cursor() as $r) {
                $profit = Dec::parse((string) $r->revenue, Dec::MONEY) - Dec::parse((string) $r->cogs, Dec::MONEY);
                yield ['item' => (string) $r->item, 'sku' => (string) $r->sku, 'qty' => Q::qty($r->qty), 'revenue' => Q::money($r->revenue), 'cogs' => Q::money($r->cogs), 'profit' => Dec::fmt($profit, Dec::MONEY),
                    'margin' => Q::pct(Dec::fmt($profit, Dec::MONEY), $r->revenue)] + ($by === 'variant' ? ['_links' => ['item' => ['table' => 'variants', 'id' => (int) $r->variant_id]]] : []);
            }
        }, static fn (array $t) => isset($t['revenue'], $t['profit']) ? ['margin' => Q::pct($t['profit'], $t['revenue'])] : [], ['compare' => true, 'branch' => true, 'group' => ['product', 'variant', 'size_label']],
            ['type' => 'bar', 'x' => 'item', 'y' => 'revenue'], ownable: true);
    }

    private static function byBrand(): ReportDef
    {
        return new ReportDef('sales-by-brand', 'Sales by brand and category', 'sales', self::ALL, [
            new Col('item', 'Item'), new Col('qty', 'Qty', 'qty', sum: true), new Col('revenue', 'Revenue (ex-VAT)', 'money', sum: true), new Col('profit', 'Profit', 'money', true, true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $label = $f->extra('group', 'brand') === 'category' ? 'c.name_en' : "COALESCE(b.name, 'No brand')";
            $q = Q::items($f, $c)->join('product_variants as v', 'v.id', '=', 'i.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')->join('categories as c', 'c.id', '=', 'p.category_id')
                ->leftJoin('product_brands as b', 'b.id', '=', 'p.brand_id')->groupByRaw($label)->orderByRaw('SUM('.Q::REV.') DESC')
                ->selectRaw("$label as item, SUM(".Q::UNITS.') as qty, SUM('.Q::REV.') as revenue, SUM('.Q::COGS.') as cogs');
            foreach ($q->cursor() as $r) {
                yield ['item' => (string) $r->item, 'qty' => Q::qty($r->qty), 'revenue' => Q::money($r->revenue), 'profit' => Dec::fmt(Dec::parse((string) $r->revenue, Dec::MONEY) - Dec::parse((string) $r->cogs, Dec::MONEY), Dec::MONEY)];
            }
        }, null, ['compare' => true, 'branch' => true, 'group' => ['brand', 'category']], ['type' => 'bar', 'x' => 'item', 'y' => 'revenue']);
    }

    private static function byVehicle(): ReportDef
    {
        return new ReportDef('sales-by-vehicle', 'Sales by vehicle make and model', 'sales', self::ALL, [
            new Col('make', 'Make'), new Col('model', 'Model'), new Col('year', 'Year'), new Col('qty', 'Qty', 'qty', sum: true), new Col('revenue', 'Revenue (ex-VAT)', 'money', sum: true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $depth = ['make' => 1, 'model' => 2, 'year' => 3][$f->extra('group', 'model')] ?? 2;
            $make = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.vehicle_snapshot, '$.make')), 'null'), 'Unknown')";
            $model = $depth >= 2 ? "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.vehicle_snapshot, '$.model')), 'null'), '')" : "''";
            $year = $depth >= 3 ? "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.vehicle_snapshot, '$.year')), 'null'), '')" : "''";
            // Grouped by the select aliases: the database does not match a JSON expression in GROUP BY to the same one in SELECT.
            $q = Q::items($f, $c)->whereNotNull('s.vehicle_snapshot')->groupByRaw('vm_make, vm_model, vm_year')->orderByRaw('SUM('.Q::REV.') DESC')
                ->selectRaw("$make as vm_make, $model as vm_model, $year as vm_year, SUM(".Q::UNITS.') as qty, SUM('.Q::REV.') as revenue');
            foreach ($q->cursor() as $r) {
                yield ['make' => (string) $r->vm_make, 'model' => (string) $r->vm_model, 'year' => (string) $r->vm_year, 'qty' => Q::qty($r->qty), 'revenue' => Q::money($r->revenue)];
            }
        }, null, ['compare' => true, 'branch' => true, 'group' => ['make', 'model', 'year']], ['type' => 'bar', 'x' => 'model', 'y' => 'revenue']);
    }

    private static function byCashier(): ReportDef
    {
        return new ReportDef('sales-by-cashier', 'Sales by cashier', 'sales', self::ALL, [
            new Col('cashier', 'Cashier'), new Col('invoices', 'Invoices', 'int', sum: true), new Col('revenue', 'Total', 'money', sum: true), new Col('discount', 'Discount', 'money', sum: true), new Col('voids', 'Voids', 'int', sum: true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $voids = DB::table('sales as s')->where('s.status', 'void')->whereNull('s.deleted_at')->where('s.sale_date', '>=', $f->fromUtc())->where('s.sale_date', '<', $f->toUtcExclusive())
                ->when($f->branchId, fn ($q) => $q->where('s.branch_id', $f->branchId))->groupBy('s.cashier_id')->selectRaw('s.cashier_id, COUNT(*) as n')->pluck('n', 'cashier_id');
            $seen = [];
            $q = Q::invoices($f, $c)->leftJoin('users as u', 'u.id', '=', 's.cashier_id')->groupBy('s.cashier_id', 'u.full_name')->orderByRaw('SUM(s.grand_total) DESC')
                ->selectRaw('s.cashier_id, COALESCE(u.full_name, \'—\') as cashier, COUNT(*) as n, SUM(s.grand_total) as revenue, SUM(s.line_discount_total + s.cart_discount_amount) as discount');
            foreach ($q->cursor() as $r) {
                $seen[(int) $r->cashier_id] = true;
                yield ['cashier' => (string) $r->cashier, 'invoices' => (int) $r->n, 'revenue' => Q::money($r->revenue), 'discount' => Q::money($r->discount), 'voids' => (int) ($voids[$r->cashier_id] ?? 0)];
            }
            // A cashier whose only invoices in the range were voided still shows, with zero sales.
            foreach ($voids as $id => $n) {
                if (! isset($seen[(int) $id])) {
                    yield ['cashier' => (string) (DB::table('users')->where('id', $id)->value('full_name') ?? '—'), 'invoices' => 0, 'revenue' => '0.00', 'discount' => '0.00', 'voids' => (int) $n];
                }
            }
        }, null, ['compare' => true, 'branch' => true]);
    }

    private static function byCustomer(): ReportDef
    {
        return new ReportDef('sales-by-customer', 'Sales by customer', 'sales', self::ALL, [
            new Col('customer', 'Customer'), new Col('invoices', 'Invoices', 'int', sum: true), new Col('revenue', 'Total', 'money', sum: true), new Col('due', 'Due', 'money', sum: true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $q = Q::invoices($f, $c)->join('customers as cu', 'cu.id', '=', 's.customer_id')->groupBy('s.customer_id', 'cu.name')->orderByRaw('SUM(s.grand_total) DESC')
                ->selectRaw('s.customer_id, cu.name as customer, COUNT(*) as n, SUM(s.grand_total) as revenue, SUM(s.due_total) as due');
            foreach ($q->cursor() as $r) {
                yield ['customer' => (string) $r->customer, 'invoices' => (int) $r->n, 'revenue' => Q::money($r->revenue), 'due' => Q::money($r->due), '_links' => ['customer' => ['table' => 'customers', 'id' => (int) $r->customer_id]]];
            }
        }, null, ['compare' => true, 'branch' => true], ['type' => 'bar', 'x' => 'customer', 'y' => 'revenue']);
    }

    private static function byPaymentMethod(): ReportDef
    {
        $names = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank_transfer' => 'Bank'];

        return new ReportDef('sales-by-payment-method', 'Collections by payment method', 'sales', self::OWN, [
            new Col('method', 'Method'), new Col('account', 'Account'), new Col('source', 'Source'), new Col('amount', 'Amount', 'money', sum: true), new Col('count', 'Payments', 'int', sum: true),
        ], static function (ReportFilters $f, ReportContext $c) use ($names) {
            $q = Q::invoices($f, $c)->join('sale_payments as p', fn ($j) => $j->on('p.sale_id', '=', 's.id')->whereNull('p.deleted_at')->where('p.verification', '<>', 'rejected'))
                ->join('financial_accounts as a', 'a.id', '=', 'p.account_id')->groupBy('p.method', 'a.name')->orderByRaw('SUM(p.amount) DESC')
                ->selectRaw('p.method, a.name as account, SUM(p.amount) as amount, COUNT(*) as n');
            foreach ($q->cursor() as $r) {
                yield ['method' => $names[$r->method] ?? $r->method, 'account' => (string) $r->account, 'source' => 'Invoice payments', 'amount' => Q::money($r->amount), 'count' => (int) $r->n];
            }
            if ($c->ownOnly) {
                return;
            }
            $later = DB::table('party_payments as p')->join('financial_accounts as a', 'a.id', '=', 'p.account_id')->where(['p.direction' => 'receive', 'p.status' => 'posted'])->where('p.verification', '<>', 'rejected')->whereNull('p.deleted_at')
                ->whereBetween('p.payment_date', [$f->from, $f->to])->when($f->branchId, fn ($qq) => $qq->where('p.branch_id', $f->branchId))
                ->groupBy('p.method', 'a.name')->orderByRaw('SUM(p.amount) DESC')->selectRaw('p.method, a.name as account, SUM(p.amount) as amount, COUNT(*) as n');
            foreach ($later->cursor() as $r) {
                yield ['method' => $names[$r->method] ?? $r->method, 'account' => (string) $r->account, 'source' => 'Later receipts', 'amount' => Q::money($r->amount), 'count' => (int) $r->n];
            }
        }, null, ['compare' => true, 'branch' => true], ['type' => 'bar', 'x' => 'method', 'y' => 'amount'], ownable: true);
    }

    private static function hourly(): ReportDef
    {
        return new ReportDef('sales-hourly', 'Hourly sales heatmap', 'sales', self::ALL, [
            new Col('weekday', 'Weekday'), new Col('hour', 'Hour'), new Col('invoices', 'Invoices', 'int', sum: true), new Col('revenue', 'Total', 'money', sum: true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $dt = self::local('s.sale_date');
            $q = Q::invoices($f, $c)->groupByRaw("MOD(DAYOFWEEK($dt), 7), HOUR($dt)")->orderByRaw("MOD(DAYOFWEEK($dt), 7), HOUR($dt)")
                ->selectRaw("MOD(DAYOFWEEK($dt), 7) as wd, HOUR($dt) as hr, COUNT(*) as n, SUM(s.grand_total) as revenue");
            foreach ($q->cursor() as $r) {
                yield ['weekday' => self::DAYS[(int) $r->wd], 'hour' => sprintf('%02d:00', (int) $r->hr), 'invoices' => (int) $r->n, 'revenue' => Q::money($r->revenue)];
            }
        }, null, ['compare' => true, 'branch' => true]);
    }

    private static function discounts(): ReportDef
    {
        return new ReportDef('discount-report', 'Discounts given', 'sales', self::ALL, [
            new Col('invoice', 'Invoice'), new Col('date', 'Date', 'date'), new Col('cashier', 'Cashier'), new Col('customer', 'Customer'), new Col('discount', 'Discount', 'money', sum: true),
            new Col('percent', 'Of gross %', 'percent'), new Col('approver', 'Approved by'),
        ], static function (ReportFilters $f, ReportContext $c) {
            $approvals = DB::table('audit_logs')->where(['entity_table' => 'sales', 'action' => 'sale_complete'])->whereNotNull('approver_id')->groupBy('entity_id')->selectRaw('entity_id, MAX(approver_id) as approver_id');
            $q = Q::invoices($f, $c)->leftJoin('users as u', 'u.id', '=', 's.cashier_id')->leftJoinSub($approvals, 'ap', 'ap.entity_id', '=', 's.id')->leftJoin('users as au', 'au.id', '=', 'ap.approver_id')
                ->whereRaw('(s.line_discount_total + s.cart_discount_amount) > 0')->orderByDesc('s.sale_date')
                ->selectRaw('s.id, s.invoice_no, '.Q::day('s.sale_date').' as day, u.full_name as cashier, s.customer_snapshot, (s.line_discount_total + s.cart_discount_amount) as discount, s.subtotal, au.full_name as approver');
            foreach ($q->cursor() as $r) {
                yield ['invoice' => (string) $r->invoice_no, 'date' => (string) $r->day, 'cashier' => (string) ($r->cashier ?? '—'), 'customer' => (string) (json_decode((string) $r->customer_snapshot, true)['name'] ?? ''),
                    'discount' => Q::money($r->discount), 'percent' => Q::pct($r->discount, $r->subtotal), 'approver' => (string) ($r->approver ?? ''), '_links' => ['invoice' => ['table' => 'sales', 'id' => (int) $r->id]]];
            }
        }, null, ['compare' => true, 'branch' => true]);
    }

    private static function returns(): ReportDef
    {
        return new ReportDef('returns-report', 'Sales returns', 'sales', self::ALL, [
            new Col('return_no', 'Return'), new Col('date', 'Date', 'date'), new Col('invoice', 'Invoice'), new Col('reason', 'Reason'), new Col('qty', 'Qty', 'qty', sum: true),
            new Col('value', 'Value', 'money', sum: true), new Col('refund', 'Refunded', 'money', sum: true), new Col('credit', 'Ledger credit', 'money', sum: true),
        ], static function (ReportFilters $f, ReportContext $c) {
            $qty = DB::table('sale_return_items')->whereNull('deleted_at')->groupBy('return_id')->selectRaw('return_id, SUM(qty) as qty');
            $q = DB::table('sale_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')->leftJoin('lookup_values as lv', 'lv.id', '=', 'r.reason_id')->leftJoinSub($qty, 'q', 'q.return_id', '=', 'r.id')
                ->where('r.status', 'posted')->whereNull('r.deleted_at')->where('r.return_date', '>=', $f->fromUtc())->where('r.return_date', '<', $f->toUtcExclusive())
                ->when($f->branchId, fn ($qq) => $qq->where('r.branch_id', $f->branchId))->orderByDesc('r.return_date')
                ->selectRaw('r.id, r.return_no, r.sale_id, s.invoice_no, '.Q::day('r.return_date').' as day, COALESCE(lv.label_en, \'—\') as reason, COALESCE(q.qty, 0) as qty, (r.refund_total + r.ledger_credit_total) as value, r.refund_total, r.ledger_credit_total');
            foreach ($q->cursor() as $r) {
                yield ['return_no' => (string) $r->return_no, 'date' => (string) $r->day, 'invoice' => (string) $r->invoice_no, 'reason' => (string) $r->reason, 'qty' => Q::qty($r->qty), 'value' => Q::money($r->value),
                    'refund' => Q::money($r->refund_total), 'credit' => Q::money($r->ledger_credit_total), '_links' => ['return_no' => ['table' => 'sale_returns', 'id' => (int) $r->id], 'invoice' => ['table' => 'sales', 'id' => (int) $r->sale_id]]];
            }
        }, null, ['compare' => true, 'branch' => true]);
    }

    private static function voids(): ReportDef
    {
        return new ReportDef('void-report', 'Voided invoices', 'sales', self::ALL, [
            new Col('invoice', 'Invoice'), new Col('date', 'Voided on', 'date'), new Col('user', 'Voided by'), new Col('value', 'Value', 'money', sum: true), new Col('reason', 'Reason'),
        ], static function (ReportFilters $f, ReportContext $c) {
            $q = DB::table('sales as s')->leftJoin('users as u', 'u.id', '=', 's.voided_by')->where('s.status', 'void')->whereNull('s.deleted_at')
                ->where('s.voided_at', '>=', $f->fromUtc())->where('s.voided_at', '<', $f->toUtcExclusive())->when($f->branchId, fn ($qq) => $qq->where('s.branch_id', $f->branchId))->orderByDesc('s.voided_at')
                ->selectRaw('s.id, s.invoice_no, '.Q::day('s.voided_at').' as day, u.full_name as user, s.grand_total, s.void_reason');
            foreach ($q->cursor() as $r) {
                yield ['invoice' => (string) $r->invoice_no, 'date' => (string) $r->day, 'user' => (string) ($r->user ?? '—'), 'value' => Q::money($r->grand_total), 'reason' => (string) $r->void_reason, '_links' => ['invoice' => ['table' => 'sales', 'id' => (int) $r->id]]];
            }
        }, null, ['compare' => true, 'branch' => true]);
    }
}
