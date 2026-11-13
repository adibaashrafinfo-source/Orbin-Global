<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Support\Allocator;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * FR-1008 and FR-1001: a due that is forgiven or discounted after the fact, and a correction of a party's balance.
 * Both are ledger entries with a reason and an audit row; nothing is edited in place.
 */
class PartyAdjustmentService
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PeriodLockService $period,
        private readonly AuditService $audit,
    ) {}

    /**
     * Write off (bad debt) or discount a due, oldest document first. The documents' due falls and their written-off total
     * rises, so invoice = paid + due + written off still holds.
     *
     * @return array<int, array{table: string, id: int, no: string, amount: string}>
     */
    public function writeOff(string $type, int $partyId, string $amount, string $kind, string $reason, ?string $date = null): array
    {
        $paisa = Dec::parse($amount, Dec::MONEY);
        $date ??= Branches::today();
        if ($paisa <= 0) {
            throw new ApiException('VALIDATION', 422, '', ['amount' => ['errors.REQUIRED']]);
        }
        $this->period->assertOpen($date);

        return DB::transaction(function () use ($type, $partyId, $paisa, $kind, $reason, $date): array {
            $this->ledger->lockParty($type, $partyId);
            $docs = $this->ledger->openDocuments($type, $partyId, true);
            if ($paisa > array_sum(array_column($docs, 'open'))) {
                throw new ApiException('ALLOCATION_EXCEEDS_DUE', 422);
            }
            $split = Allocator::oldestFirst($docs, $paisa);
            $byKey = [];
            foreach ($docs as $d) {
                $byKey[$d['table'].':'.$d['id']] = $d;
            }
            $done = [];
            foreach ($split['allocations'] as $a) {
                $doc = $byKey[$a['table'].':'.$a['id']];
                if ($a['table'] === 'sales') {
                    $sale = DB::table('sales')->where('id', $a['id'])->first(['due_total', 'written_off_total']);
                    $due = Dec::parse((string) $sale->due_total, Dec::MONEY) - $a['amount'];
                    DB::table('sales')->where('id', $a['id'])->update([
                        'due_total' => Dec::fmt($due, Dec::MONEY), 'written_off_total' => Dec::fmt(Dec::parse((string) $sale->written_off_total, Dec::MONEY) + $a['amount'], Dec::MONEY),
                        'payment_status' => $due === 0 ? 'paid' : 'partial',
                    ]);
                } elseif ($a['table'] === 'goods_receipts') {
                    DB::table('goods_receipts')->where('id', $a['id'])->update(['written_off_total' => DB::raw('written_off_total + '.Dec::fmt($a['amount'], Dec::MONEY))]);
                }
                // A customer's forgiven due is a credit (they owe less); a supplier's discount is a debit (we owe less).
                $this->ledger->post($type, $partyId, $kind, $type === 'customer' ? 0 : $a['amount'], $type === 'customer' ? $a['amount'] : 0, $a['table'], $a['id'], $doc['no'], ucfirst($kind).': '.$reason, $date);
                $done[] = ['table' => $a['table'], 'id' => $a['id'], 'no' => $doc['no'], 'amount' => Dec::fmt($a['amount'], Dec::MONEY)];
            }
            $this->audit->log('party_'.$kind, $type === 'customer' ? 'customers' : 'suppliers', $partyId, null, ['amount' => Dec::fmt($paisa, Dec::MONEY), 'kind' => $kind, 'documents' => $done], $reason);

            return $done;
        }, 3);
    }

    /** Correct a party's balance by a ledger adjustment (for instance a wrong opening balance). It changes the balance, not any one document. */
    public function adjust(string $type, int $partyId, string $direction, string $amount, string $reason, ?string $date = null): void
    {
        $paisa = Dec::parse($amount, Dec::MONEY);
        $date ??= Branches::today();
        if ($paisa <= 0) {
            throw new ApiException('VALIDATION', 422, '', ['amount' => ['errors.REQUIRED']]);
        }
        $this->period->assertOpen($date);

        DB::transaction(function () use ($type, $partyId, $direction, $paisa, $reason, $date): void {
            $this->ledger->lockParty($type, $partyId);
            // "increase" = the party owes more: customer debit, supplier credit.
            $debit = ($type === 'customer') === ($direction === 'increase');
            $this->ledger->post($type, $partyId, 'adjustment', $debit ? $paisa : 0, $debit ? 0 : $paisa, null, null, null, 'Adjustment: '.$reason, $date);
            $this->audit->log('party_adjustment', $type === 'customer' ? 'customers' : 'suppliers', $partyId, null, ['direction' => $direction, 'amount' => Dec::fmt($paisa, Dec::MONEY)], $reason);
        }, 3);
    }
}
