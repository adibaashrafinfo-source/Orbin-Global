<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Services\AuditService;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FR-1007: who owes us and whom we owe, with aging. The amount is always the party's ledger balance. It is spread over
 * the open documents newest first, because advances and returns settle the oldest dues first (BR-062), so the buckets
 * always add up to the balance the ledger shows.
 */
class DuesService
{
    public const BUCKETS = ['d0_15', 'd16_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct(private readonly PartyLedgerService $ledger, private readonly AuditService $audit) {}

    /**
     * @param  array{search?: string|null, bucket?: string|null, sort?: string|null, page?: int, per_page?: int}  $filters
     * @return array{data: array<int, array<string, mixed>>, summary: array<string, mixed>, meta: array<string, int>}
     */
    public function list(string $type, array $filters): array
    {
        $rows = $this->rows($type);
        $summary = ['parties' => count($rows), 'total' => 0, 'buckets' => array_fill_keys(self::BUCKETS, 0)];
        foreach ($rows as $r) {
            $summary['total'] += $r['balance'];
            foreach (self::BUCKETS as $b) {
                $summary['buckets'][$b] += $r['buckets'][$b];
            }
        }

        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $bucket = $filters['bucket'] ?? null;
        $rows = array_values(array_filter($rows, fn ($r) => ($search === '' || str_contains($r['search'], $search)) && ($bucket === null || $bucket === '' || $r['buckets'][$bucket] > 0)));
        $sort = $filters['sort'] ?? 'balance';
        usort($rows, match ($sort) {
            'oldest' => fn ($a, $b) => strcmp((string) $a['oldest_date'], (string) $b['oldest_date']) ?: $b['balance'] <=> $a['balance'],
            'name' => fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            default => fn ($a, $b) => $b['balance'] <=> $a['balance'],
        });

        $per = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $slice = array_slice($rows, ($page - 1) * $per, $per);

        return [
            'data' => array_map(fn ($r) => $this->present($r), $slice),
            'summary' => ['parties' => $summary['parties'], 'total' => Dec::fmt($summary['total'], Dec::MONEY), 'buckets' => array_map(fn ($v) => Dec::fmt($v, Dec::MONEY), $summary['buckets'])],
            'meta' => ['current_page' => $page, 'per_page' => $per, 'total' => count($rows), 'last_page' => max(1, (int) ceil(count($rows) / $per))],
        ];
    }

    /** Promise-to-pay for a customer, or the date we plan to pay a supplier. An empty date clears both fields. */
    public function setPromise(string $type, int $partyId, ?string $date, ?string $note): void
    {
        $table = $type === 'customer' ? 'customers' : 'suppliers';
        $row = DB::table($table)->where('id', $partyId)->whereNull('deleted_at')->first(['promise_date', 'promise_note']);
        if ($row === null) {
            throw ApiException::notFound();
        }
        $data = ['promise_date' => $date ?: null, 'promise_note' => $date ? ($note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null) : null];
        DB::table($table)->where('id', $partyId)->update($data);
        $this->audit->log('promise_set', $table, $partyId, ['promise_date' => $row->promise_date, 'promise_note' => $row->promise_note], $data);
    }

    /** @return array<int, array<string, mixed>> parties with a positive balance, with their buckets in paisa */
    private function rows(string $type): array
    {
        $today = CarbonImmutable::parse(Branches::today());
        $sums = DB::table('party_ledger_entries')->where('party_type', $type)->groupBy('party_id')->selectRaw('party_id, SUM(debit) as d, SUM(credit) as c, MAX(CASE WHEN entry_type = \'payment\' THEN entry_date END) as last_payment')->get();
        $balances = [];
        $lastPayment = [];
        foreach ($sums as $s) {
            $b = Dec::parse((string) $s->d, Dec::MONEY) - Dec::parse((string) $s->c, Dec::MONEY);
            $b = $type === 'customer' ? $b : -$b;
            if ($b > 0) {
                $balances[(int) $s->party_id] = $b;
                $lastPayment[(int) $s->party_id] = $s->last_payment;
            }
        }
        if ($balances === []) {
            return [];
        }
        $ids = array_keys($balances);
        $table = $type === 'customer' ? 'customers' : 'suppliers';
        $parties = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->whereNull('deleted_at')->get() as $p) {
                $parties[(int) $p->id] = $p;
            }
        }
        $docs = $this->ledger->openDocumentsFor($type, array_keys($parties));

        $out = [];
        foreach ($parties as $id => $p) {
            $balance = $balances[$id];
            $buckets = array_fill_keys(self::BUCKETS, 0);
            $kept = $this->netDocuments($docs[$id] ?? [], $balance);
            foreach ($kept['docs'] as $d) {
                $buckets[$this->bucket((int) $today->diffInDays(CarbonImmutable::parse($d['date']), true))] += $d['open'];
            }
            // A balance no document explains (an adjustment) counts as current: it has no date to age it by.
            $buckets['d0_15'] += $kept['unexplained'];
            $phone = $type === 'customer' ? ($p->phone_primary ?? null) : (json_decode((string) ($p->phones ?? '[]'), true)[0] ?? null);
            $out[] = [
                'type' => $type, 'id' => $id, 'code' => $p->code, 'name' => $p->name, 'phone' => $phone, 'balance' => $balance, 'buckets' => $buckets,
                'oldest_date' => $kept['docs'][0]['date'] ?? null, 'open_documents' => count($kept['docs']), 'last_payment' => $lastPayment[$id],
                'promise_date' => $p->promise_date, 'promise_note' => $p->promise_note,
                'search' => mb_strtolower(trim($p->code.' '.$p->name.' '.$phone)),
            ];
        }

        return $out;
    }

    /**
     * Keep only the newest documents that add up to the balance. Older ones were already settled by advances and credits.
     *
     * @param  array<int, array{table: string, id: int, no: string, date: string, total: int, open: int}>  $docs  oldest first
     * @return array{docs: array<int, array{date: string, open: int}>, unexplained: int}
     */
    private function netDocuments(array $docs, int $balance): array
    {
        $left = $balance;
        $kept = [];
        foreach (array_reverse($docs) as $d) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $d['open']);
            $kept[] = ['date' => $d['date'], 'open' => $take];
            $left -= $take;
        }

        return ['docs' => array_reverse($kept), 'unexplained' => $left];
    }

    private function bucket(int $days): string
    {
        return match (true) {
            $days <= 15 => 'd0_15',
            $days <= 30 => 'd16_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /** @param  array<string, mixed>  $r */
    private function present(array $r): array
    {
        unset($r['search']);
        $r['balance'] = Dec::fmt($r['balance'], Dec::MONEY);
        $r['buckets'] = array_map(fn ($v) => Dec::fmt($v, Dec::MONEY), $r['buckets']);

        return $r;
    }
}
