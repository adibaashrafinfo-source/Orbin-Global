<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Supplier;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The party ledger (FR-1001) and the documents a party still owes on. A customer owes us (debit − credit); we owe a
 * supplier (credit − debit). "Open documents" are what payments are allocated to (FR-1002, BR-062): the opening balance
 * first, then invoices or goods receipts oldest first. Amounts are paisa.
 */
class PartyLedgerService
{
    public const OPENING_TABLE = 'party_ledger_entries';

    /** Lock and return the customer or supplier row. Every money movement for one party serialises on it. */
    public function lockParty(string $type, int $id): Model
    {
        $party = $type === 'customer' ? Customer::query()->whereKey($id)->lockForUpdate()->first() : Supplier::query()->whereKey($id)->lockForUpdate()->first();
        if ($party === null) {
            throw ApiException::notFound();
        }

        return $party;
    }

    public function balance(string $type, int $id): int
    {
        $row = DB::table('party_ledger_entries')->where(['party_type' => $type, 'party_id' => $id])->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
        $d = Dec::parse((string) $row->d, Dec::MONEY);
        $c = Dec::parse((string) $row->c, Dec::MONEY);

        return $type === 'customer' ? $d - $c : $c - $d;
    }

    /** Post one ledger line. Exactly one of debit and credit is above zero. */
    public function post(string $type, int $id, string $entryType, int $debit, int $credit, ?string $refTable, ?int $refId, ?string $refNo, string $description, string $date, ?int $branchId = null): PartyLedgerEntry
    {
        return PartyLedgerEntry::query()->create([
            'branch_id' => $branchId ?? Branches::currentId(), 'party_type' => $type, 'party_id' => $id,
            'entry_date' => $date, 'entry_type' => $entryType,
            'debit' => Dec::fmt($debit, Dec::MONEY), 'credit' => Dec::fmt($credit, Dec::MONEY),
            'ref_table' => $refTable, 'ref_id' => $refId, 'ref_no' => $refNo, 'description' => $description,
        ]);
    }

    /**
     * What the party still owes on each document, oldest first. Lock the party row first (lockParty), then pass $lock to
     * lock the document rows too, in id order.
     *
     * @return array<int, array{table: string, id: int, no: string, date: string, total: int, open: int}>
     */
    public function openDocuments(string $type, int $id, bool $lock = false): array
    {
        return $this->openDocumentsFor($type, [$id], $lock)[$id] ?? [];
    }

    /**
     * The same for many parties in a handful of queries (the dues screens).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, array{table: string, id: int, no: string, date: string, total: int, open: int}>> keyed by party id
     */
    public function openDocumentsFor(string $type, array $ids, bool $lock = false): array
    {
        if ($ids === []) {
            return [];
        }
        $docs = array_fill_keys($ids, []);
        foreach ($this->openingDocuments($type, $ids) as $partyId => $list) {
            array_push($docs[$partyId], ...$list);
        }
        $others = $type === 'customer' ? $this->invoiceDocuments($ids, $lock) : $this->receiptDocuments($ids, $lock);
        foreach ($others as $partyId => $list) {
            array_push($docs[$partyId], ...$list);
        }
        foreach ($docs as $partyId => $list) {
            $docs[$partyId] = array_values(array_filter($list, fn ($d) => $d['open'] > 0));
        }

        return $docs;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<int, array{table: string, id: int, no: string, date: string, total: int, open: int}>>
     */
    private function openingDocuments(string $type, array $ids): array
    {
        $side = $type === 'customer' ? 'debit' : 'credit';
        $settledSide = $type === 'customer' ? 'credit' : 'debit';
        $entries = DB::table('party_ledger_entries')->where('party_type', $type)->whereIn('party_id', $ids)->where('entry_type', 'opening')->orderBy('id')->get();
        if ($entries->isEmpty()) {
            return [];
        }
        $entryIds = $entries->pluck('id');
        $paid = DB::table('party_payment_allocations as a')->join('party_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.doc_table', self::OPENING_TABLE)->whereIn('a.doc_id', $entryIds)->where('p.status', 'posted')->groupBy('a.doc_id')->selectRaw('a.doc_id, SUM(a.amount) as t')->pluck('t', 'doc_id');
        $forgiven = DB::table('party_ledger_entries')->where('party_type', $type)->where('ref_table', self::OPENING_TABLE)->whereIn('ref_id', $entryIds)->whereIn('entry_type', ['writeoff', 'discount'])
            ->groupBy('ref_id')->selectRaw("ref_id, SUM({$settledSide}) as t")->pluck('t', 'ref_id');
        $out = [];
        foreach ($entries as $e) {
            $total = Dec::parse((string) $e->{$side}, Dec::MONEY);
            if ($total <= 0) {
                continue;
            }
            $open = $total - Dec::parse((string) ($paid[$e->id] ?? '0'), Dec::MONEY) - Dec::parse((string) ($forgiven[$e->id] ?? '0'), Dec::MONEY);
            $out[(int) $e->party_id][] = ['table' => self::OPENING_TABLE, 'id' => (int) $e->id, 'no' => 'OPENING', 'date' => (string) $e->entry_date, 'total' => $total, 'open' => max(0, $open)];
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<int, array{table: string, id: int, no: string, date: string, total: int, open: int}>>
     */
    private function invoiceDocuments(array $ids, bool $lock): array
    {
        $q = DB::table('sales')->whereIn('customer_id', $ids)->whereIn('status', ['completed', 'partially_returned', 'returned'])->whereNull('deleted_at')->where('due_total', '>', 0);
        if ($lock) {
            (clone $q)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $sales = $q->get(['id', 'customer_id', 'invoice_no', 'sale_date', 'grand_total', 'due_total'])->sortBy(fn ($s) => [(string) $s->sale_date, $s->id])->values();
        // The part of a return that was not refunded in cash was credited to the ledger; it settles that invoice's due first.
        $credits = DB::table('sale_returns')->whereIn('sale_id', $sales->pluck('id'))->where('status', 'posted')->whereNull('deleted_at')->groupBy('sale_id')->selectRaw('sale_id, SUM(ledger_credit_total) as c')->pluck('c', 'sale_id');
        $out = [];
        foreach ($sales as $s) {
            $credit = Dec::parse((string) ($credits[$s->id] ?? '0'), Dec::MONEY);
            $out[(int) $s->customer_id][] = [
                'table' => 'sales', 'id' => (int) $s->id, 'no' => (string) $s->invoice_no, 'date' => CarbonImmutable::parse((string) $s->sale_date, 'UTC')->setTimezone('Asia/Dhaka')->toDateString(),
                'total' => Dec::parse((string) $s->grand_total, Dec::MONEY), 'open' => max(0, Dec::parse((string) $s->due_total, Dec::MONEY) - $credit),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<int, array{table: string, id: int, no: string, date: string, total: int, open: int}>>
     */
    private function receiptDocuments(array $ids, bool $lock): array
    {
        $q = DB::table('goods_receipts')->whereIn('supplier_id', $ids)->where('status', 'posted')->whereNull('deleted_at');
        if ($lock) {
            (clone $q)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $grns = $q->orderBy('received_date')->orderBy('id')->get(['id', 'supplier_id', 'grn_no', 'received_date', 'grand_total', 'paid_total', 'written_off_total']);
        $credits = DB::table('purchase_returns')->whereIn('grn_id', $grns->pluck('id'))->where('status', 'posted')->whereNull('deleted_at')->groupBy('grn_id')->selectRaw('grn_id, SUM(total) as c')->pluck('c', 'grn_id');
        $out = [];
        foreach ($grns as $g) {
            $total = Dec::parse((string) $g->grand_total, Dec::MONEY);
            $open = $total - Dec::parse((string) $g->paid_total, Dec::MONEY) - Dec::parse((string) $g->written_off_total, Dec::MONEY) - Dec::parse((string) ($credits[$g->id] ?? '0'), Dec::MONEY);
            $out[(int) $g->supplier_id][] = ['table' => 'goods_receipts', 'id' => (int) $g->id, 'no' => (string) $g->grn_no, 'date' => (string) $g->received_date, 'total' => $total, 'open' => max(0, $open)];
        }

        return $out;
    }

    /**
     * Entries between two dates with the running balance, for the screen and the statement PDF.
     *
     * @return array{opening: int, closing: int, total_debit: int, total_credit: int, rows: array<int, array<string, mixed>>}
     */
    public function statement(string $type, int $id, ?string $from, ?string $to): array
    {
        $sign = $type === 'customer' ? 1 : -1;
        $base = DB::table('party_ledger_entries')->where(['party_type' => $type, 'party_id' => $id]);
        $opening = 0;
        if ($from !== null) {
            $row = (clone $base)->where('entry_date', '<', $from)->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
            $opening = $sign * (Dec::parse((string) $row->d, Dec::MONEY) - Dec::parse((string) $row->c, Dec::MONEY));
        }
        $entries = (clone $base)->when($from !== null, fn ($q) => $q->where('entry_date', '>=', $from))->when($to !== null, fn ($q) => $q->where('entry_date', '<=', $to))
            ->orderBy('entry_date')->orderBy('id')->get();
        $running = $opening;
        $rows = [];
        $td = 0;
        $tc = 0;
        foreach ($entries as $e) {
            $d = Dec::parse((string) $e->debit, Dec::MONEY);
            $c = Dec::parse((string) $e->credit, Dec::MONEY);
            $running += $sign * ($d - $c);
            $td += $d;
            $tc += $c;
            $rows[] = [
                'id' => (int) $e->id, 'date' => (string) $e->entry_date, 'type' => $e->entry_type, 'ref_table' => $e->ref_table, 'ref_id' => $e->ref_id !== null ? (int) $e->ref_id : null,
                'ref_no' => $e->ref_no, 'description' => $e->description, 'debit' => Dec::fmt($d, Dec::MONEY), 'credit' => Dec::fmt($c, Dec::MONEY), 'balance' => Dec::fmt($running, Dec::MONEY),
            ];
        }

        return ['opening' => $opening, 'closing' => $running, 'total_debit' => $td, 'total_credit' => $tc, 'rows' => $rows];
    }
}
