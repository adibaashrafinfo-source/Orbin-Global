<?php

namespace App\Reports\Defs;

use App\Reports\CarbonLocal;
use App\Reports\Col;
use App\Reports\Q;
use App\Reports\ReportDef;
use App\Reports\ReportFilters;
use App\Services\Finance\DayBookService;
use App\Services\Finance\DuesService;
use App\Support\Branches;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Money reports. Balances, dues and the day book come from the Phase 4 services, so a report and its screen always agree. */
final class FinanceReports
{
    private const PERM = ['reports.view_all'];

    private const METHOD = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank_transfer' => 'Bank'];

    /** @return array<int, ReportDef> */
    public static function defs(): array
    {
        return [self::aging('receivable-aging', 'Customer dues and aging', 'customer'), self::aging('payable-aging', 'Supplier dues and aging', 'supplier'), self::balances(), self::dayBook(), self::expenses(), self::transfers(), self::shifts(), self::pending()];
    }

    private static function aging(string $id, string $name, string $type): ReportDef
    {
        return new ReportDef($id, $name, 'finance', self::PERM, [
            new Col('party', $type === 'customer' ? 'Customer' : 'Supplier'), new Col('code', 'Code'), new Col('d0_15', '0–15 days', 'money', sum: true), new Col('d16_30', '16–30 days', 'money', sum: true),
            new Col('d31_60', '31–60 days', 'money', sum: true), new Col('d61_90', '61–90 days', 'money', sum: true), new Col('d90_plus', 'Over 90 days', 'money', sum: true), new Col('balance', 'Total due', 'money', sum: true),
            new Col('oldest', 'Oldest due', 'date'), new Col('promise', 'Promise to pay', 'date'),
        ], static function () use ($type) {
            foreach (app(DuesService::class)->everyone($type) as $r) {
                yield ['party' => $r['name'], 'code' => $r['code'], 'd0_15' => $r['buckets']['d0_15'], 'd16_30' => $r['buckets']['d16_30'], 'd31_60' => $r['buckets']['d31_60'], 'd61_90' => $r['buckets']['d61_90'],
                    'd90_plus' => $r['buckets']['d90_plus'], 'balance' => $r['balance'], 'oldest' => (string) ($r['oldest_date'] ?? ''), 'promise' => (string) ($r['promise_date'] ?? ''),
                    '_links' => ['party' => ['table' => $type === 'customer' ? 'customers' : 'suppliers', 'id' => (int) $r['id']]]];
            }
        }, null, ['range' => 'none'], ['type' => 'bar', 'x' => 'party', 'y' => 'balance']);
    }

    private static function balances(): ReportDef
    {
        return new ReportDef('account-balances', 'Cash, bKash, Nagad, Rocket and bank balances', 'finance', self::PERM, [
            new Col('account', 'Account'), new Col('type', 'Type'), new Col('opening', 'Opening', 'money', sum: true), new Col('in', 'Money in', 'money', sum: true), new Col('out', 'Money out', 'money', sum: true), new Col('closing', 'Closing', 'money', sum: true),
        ], static function (ReportFilters $f) {
            foreach (app(DayBookService::class)->run(['from' => $f->from, 'to' => $f->to, 'per_page' => 1])['accounts'] as $a) {
                yield ['account' => $a['name'], 'type' => $a['account_type'], 'opening' => $a['opening'], 'in' => $a['in'], 'out' => $a['out'], 'closing' => $a['closing']];
            }
        }, null, ['compare' => true], ['type' => 'bar', 'x' => 'account', 'y' => 'closing']);
    }

    private static function dayBook(): ReportDef
    {
        return new ReportDef('day-book', 'Day book', 'finance', self::PERM, [
            new Col('date', 'Date', 'date'), new Col('account', 'Account'), new Col('entry', 'Entry'), new Col('ref_no', 'Reference'), new Col('party', 'Party'), new Col('in', 'Money in', 'money', sum: true), new Col('out', 'Money out', 'money', sum: true),
        ], static function (ReportFilters $f) {
            $book = app(DayBookService::class);
            for ($page = 1; ; $page++) {
                $out = $book->run(['from' => $f->from, 'to' => $f->to, 'account_id' => $f->extra('account_id'), 'page' => $page, 'per_page' => 200]);
                foreach ($out['rows'] as $r) {
                    yield ['date' => $r['date'], 'account' => $r['account_name'], 'entry' => str_replace('_', ' ', $r['ref_type']), 'ref_no' => (string) ($r['ref_no'] ?? ''), 'party' => (string) ($r['party_name'] ?? ''),
                        'in' => $r['direction'] === 'in' ? $r['amount'] : '0.00', 'out' => $r['direction'] === 'out' ? $r['amount'] : '0.00'];
                }
                if ($page >= $out['meta']['last_page']) {
                    break;
                }
            }
        }, null, ['compare' => true]);
    }

    private static function expenses(): ReportDef
    {
        return new ReportDef('expense-report', 'Expenses', 'finance', self::PERM, [
            new Col('item', 'Item'), new Col('count', 'Entries', 'int', sum: true), new Col('amount', 'Amount', 'money', sum: true),
        ], static function (ReportFilters $f) {
            $label = $f->extra('group', 'category') === 'month' ? "DATE_FORMAT(e.expense_date, '%Y-%m')" : 'c.name_en';
            $q = DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.category_id')->where('e.status', 'posted')->whereNull('e.deleted_at')->whereBetween('e.expense_date', [$f->from, $f->to])
                ->when($f->branchId, fn ($qq) => $qq->where('e.branch_id', $f->branchId))->groupByRaw($label)->orderByRaw('SUM(e.amount) DESC')->selectRaw("$label as item, COUNT(*) as n, SUM(e.amount) as amount");
            foreach ($q->cursor() as $r) {
                yield ['item' => (string) $r->item, 'count' => (int) $r->n, 'amount' => Q::money($r->amount)];
            }
        }, null, ['compare' => true, 'branch' => true, 'group' => ['category', 'month']], ['type' => 'bar', 'x' => 'item', 'y' => 'amount']);
    }

    private static function transfers(): ReportDef
    {
        return new ReportDef('fund-transfer-report', 'Fund transfers', 'finance', self::PERM, [
            new Col('transfer_no', 'Transfer'), new Col('date', 'Date', 'date'), new Col('from', 'From'), new Col('to', 'To'), new Col('amount', 'Amount', 'money', sum: true), new Col('fee', 'Charge', 'money', sum: true),
        ], static function (ReportFilters $f) {
            $q = DB::table('fund_transfers as t')->join('financial_accounts as a', 'a.id', '=', 't.from_account_id')->join('financial_accounts as b', 'b.id', '=', 't.to_account_id')->where('t.status', 'posted')->whereNull('t.deleted_at')
                ->whereBetween('t.transfer_date', [$f->from, $f->to])->when($f->branchId, fn ($qq) => $qq->where('t.branch_id', $f->branchId))->orderBy('t.transfer_date')->orderBy('t.id')
                ->selectRaw('t.transfer_no, t.transfer_date, a.name as from_name, b.name as to_name, t.amount, t.fee_amount');
            foreach ($q->cursor() as $r) {
                yield ['transfer_no' => (string) $r->transfer_no, 'date' => (string) $r->transfer_date, 'from' => (string) $r->from_name, 'to' => (string) $r->to_name, 'amount' => Q::money($r->amount), 'fee' => Q::money($r->fee_amount)];
            }
        }, null, ['compare' => true, 'branch' => true]);
    }

    private static function shifts(): ReportDef
    {
        return new ReportDef('shift-z-report', 'Cashier shift / Z-report', 'finance', self::PERM, [
            new Col('shift_no', 'Shift'), new Col('cashier', 'Cashier'), new Col('opened', 'Opened', 'datetime'), new Col('closed', 'Closed', 'datetime'), new Col('opening', 'Opening float', 'money', sum: true),
            new Col('sales', 'Sales', 'money', sum: true), new Col('expected', 'Expected cash', 'money', sum: true), new Col('counted', 'Counted cash', 'money', sum: true), new Col('variance', 'Variance', 'money', sum: true),
        ], static function (ReportFilters $f) {
            $sales = DB::table('sales')->whereIn('status', Q::SOLD)->whereNull('deleted_at')->groupBy('shift_id')->selectRaw('shift_id, SUM(grand_total) as total');
            $q = DB::table('cash_shifts as c')->join('users as u', 'u.id', '=', 'c.cashier_id')->leftJoinSub($sales, 'sl', 'sl.shift_id', '=', 'c.id')->where('c.status', 'closed')->whereNull('c.deleted_at')
                ->where('c.closed_at', '>=', $f->fromUtc())->where('c.closed_at', '<', $f->toUtcExclusive())->when($f->branchId, fn ($qq) => $qq->where('c.branch_id', $f->branchId))->orderBy('c.closed_at')
                ->selectRaw('c.id, c.shift_no, u.full_name as cashier, c.opened_at, c.closed_at, c.opening_float, COALESCE(sl.total, 0) as sales, c.expected_cash, c.counted_cash, c.variance');
            foreach ($q->cursor() as $r) {
                yield ['shift_no' => (string) $r->shift_no, 'cashier' => (string) $r->cashier, 'opened' => substr(CarbonLocal::at((string) $r->opened_at), 0, 16), 'closed' => substr(CarbonLocal::at((string) $r->closed_at), 0, 16),
                    'opening' => Q::money($r->opening_float), 'sales' => Q::money($r->sales), 'expected' => Q::money($r->expected_cash), 'counted' => Q::money($r->counted_cash), 'variance' => Q::money($r->variance),
                    '_links' => ['shift_no' => ['table' => 'cash_shifts', 'id' => (int) $r->id]]];
            }
        }, null, ['compare' => true, 'branch' => true]);
    }

    private static function pending(): ReportDef
    {
        return new ReportDef('pending-payments', 'Pending verification payments', 'finance', self::PERM, [
            new Col('source', 'Source'), new Col('ref_no', 'Reference'), new Col('date', 'Date', 'date'), new Col('party', 'Party'), new Col('method', 'Method'), new Col('trx', 'TrxID'), new Col('amount', 'Amount', 'money', sum: true), new Col('age', 'Age (days)', 'int'),
        ], static function () {
            $today = Branches::today();
            $a = DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->where('p.verification', 'pending')->whereNull('p.deleted_at')->whereIn('s.status', Q::SOLD)->orderBy('p.received_at')
                ->selectRaw('s.id, s.invoice_no, '.Q::day('p.received_at').' as day, s.customer_snapshot, p.method, p.trx_id, p.amount');
            foreach ($a->cursor() as $r) {
                yield ['source' => 'Invoice payment', 'ref_no' => (string) $r->invoice_no, 'date' => (string) $r->day, 'party' => (string) (json_decode((string) $r->customer_snapshot, true)['name'] ?? ''), 'method' => self::METHOD[$r->method] ?? $r->method,
                    'trx' => (string) ($r->trx_id ?? ''), 'amount' => Q::money($r->amount), 'age' => (int) CarbonImmutable::parse($r->day)->diffInDays($today, true), '_links' => ['ref_no' => ['table' => 'sales', 'id' => (int) $r->id]]];
            }
            $b = DB::table('party_payments as p')->where(['p.verification' => 'pending', 'p.status' => 'posted'])->whereNull('p.deleted_at')->orderBy('p.payment_date')->select(['p.payment_no', 'p.payment_date', 'p.party_type', 'p.party_id', 'p.method', 'p.trx_id', 'p.amount']);
            foreach ($b->cursor() as $r) {
                $party = DB::table($r->party_type === 'customer' ? 'customers' : 'suppliers')->where('id', $r->party_id)->value('name');
                yield ['source' => 'Later receipt', 'ref_no' => (string) $r->payment_no, 'date' => (string) $r->payment_date, 'party' => (string) $party, 'method' => self::METHOD[$r->method] ?? $r->method, 'trx' => (string) ($r->trx_id ?? ''),
                    'amount' => Q::money($r->amount), 'age' => (int) CarbonImmutable::parse((string) $r->payment_date)->diffInDays($today, true)];
            }
        }, null, ['range' => 'none']);
    }
}
