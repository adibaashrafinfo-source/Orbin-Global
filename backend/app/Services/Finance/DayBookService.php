<?php

namespace App\Services\Finance;

use App\Models\FinancialAccount;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-1006: every taka in and out across the accounts for a date range, with each account's opening and closing balance. */
class DayBookService
{
    /**
     * @param  array{from: string, to: string, account_id?: int|null, page?: int, per_page?: int}  $f
     * @return array{accounts: array<int, array<string, mixed>>, totals: array<string, string>, rows: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function run(array $f): array
    {
        $from = $f['from'];
        $to = $f['to'];
        $accountId = isset($f['account_id']) ? (int) $f['account_id'] : null;

        $accounts = FinancialAccount::query()->when($accountId, fn ($q) => $q->whereKey($accountId))->orderBy('account_type')->orderBy('id')->get();
        $before = $this->sumsBefore($from);
        $within = DB::table('account_transactions')->whereBetween('txn_date', [$from, $to])->when($accountId, fn ($q) => $q->where('account_id', $accountId))->groupBy('account_id')
            ->selectRaw("account_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as i, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as o")->get()->keyBy('account_id');

        $summary = [];
        $totals = ['opening' => 0, 'in' => 0, 'out' => 0, 'closing' => 0];
        foreach ($accounts as $a) {
            $opening = Dec::parse((string) $a->opening_balance, Dec::MONEY) + ($before[$a->id] ?? 0);
            $in = Dec::parse((string) ($within[$a->id]->i ?? '0'), Dec::MONEY);
            $out = Dec::parse((string) ($within[$a->id]->o ?? '0'), Dec::MONEY);
            $summary[] = ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'account_type' => $a->account_type, 'opening' => Dec::fmt($opening, Dec::MONEY), 'in' => Dec::fmt($in, Dec::MONEY), 'out' => Dec::fmt($out, Dec::MONEY), 'closing' => Dec::fmt($opening + $in - $out, Dec::MONEY)];
            $totals['opening'] += $opening;
            $totals['in'] += $in;
            $totals['out'] += $out;
            $totals['closing'] += $opening + $in - $out;
        }

        $q = DB::table('account_transactions as t')->join('financial_accounts as a', 'a.id', '=', 't.account_id')
            ->leftJoin('customers as c', fn ($j) => $j->on('c.id', '=', 't.counterparty_id')->where('t.counterparty_type', '=', 'customer'))
            ->leftJoin('suppliers as s', fn ($j) => $j->on('s.id', '=', 't.counterparty_id')->where('t.counterparty_type', '=', 'supplier'))
            ->whereBetween('t.txn_date', [$from, $to])->when($accountId, fn ($qq) => $qq->where('t.account_id', $accountId));
        $total = (clone $q)->count();
        $per = max(1, min(200, (int) ($f['per_page'] ?? 50)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $rows = $q->orderBy('t.txn_date')->orderBy('t.occurred_at')->orderBy('t.id')->forPage($page, $per)
            ->get(['t.id', 't.txn_date', 't.occurred_at', 't.direction', 't.amount', 't.ref_type', 't.ref_id', 't.ref_no', 't.description', 't.account_id', 'a.name as account_name', 't.counterparty_type', 'c.name as customer_name', 's.name as supplier_name'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'date' => (string) $r->txn_date, 'time' => (string) $r->occurred_at, 'direction' => $r->direction, 'amount' => Dec::fmt(Dec::parse((string) $r->amount, Dec::MONEY), Dec::MONEY),
                'ref_type' => $r->ref_type, 'ref_id' => $r->ref_id !== null ? (int) $r->ref_id : null, 'ref_no' => $r->ref_no, 'description' => $r->description,
                'account_id' => (int) $r->account_id, 'account_name' => $r->account_name, 'party_type' => $r->counterparty_type, 'party_name' => $r->customer_name ?? $r->supplier_name,
            ])->all();

        return [
            'accounts' => $summary,
            'totals' => array_map(fn ($v) => Dec::fmt($v, Dec::MONEY), $totals),
            'rows' => $rows,
            'meta' => ['current_page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $per))],
        ];
    }

    /** @return array<int, int> net movement (in − out, paisa) before the date, per account */
    private function sumsBefore(string $from): array
    {
        $out = [];
        foreach (DB::table('account_transactions')->where('txn_date', '<', $from)->groupBy('account_id')->selectRaw("account_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) as net")->get() as $r) {
            $out[(int) $r->account_id] = Dec::parse((string) $r->net, Dec::MONEY);
        }

        return $out;
    }
}
