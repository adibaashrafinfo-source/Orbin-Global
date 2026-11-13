<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\PartyPayment;
use App\Models\PartyPaymentAllocation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Services\NumberService;
use App\Services\PermissionService;
use App\Services\Sales\ApprovalService;
use App\Services\SettingsService;
use App\Support\Allocator;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-1002, BR-060 to BR-062: a customer pays a due later, or we pay a supplier. The money is allocated to the party's
 * open documents oldest first (or as the user splits it); whatever is left stays as an advance. One transaction, the party
 * row locked first so two payments for the same party can never allocate the same due twice.
 */
class PartyPaymentService
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly AccountLedgerService $accounts,
        private readonly PaymentReferences $references,
        private readonly ApprovalService $approvals,
        private readonly PermissionService $permissions,
        private readonly SettingsService $settings,
        private readonly NumberService $numbers,
        private readonly PeriodLockService $period,
        private readonly AuditService $audit,
        private readonly FinancePdfService $pdf,
    ) {}

    /** The permission a user needs to take or make a payment for this kind of party. */
    public static function permissionFor(string $partyType): string
    {
        return $partyType === 'customer' ? 'ledger.receive_payment' : 'ledger.pay_supplier';
    }

    /**
     * What the party owes and on which documents, for the allocation screen.
     *
     * @return array{balance: string, documents: array<int, array<string, mixed>>}
     */
    public function openDocuments(User $user, string $type, int $partyId): array
    {
        if (! $this->permissions->userHas($user, self::permissionFor($type)) && ! $this->permissions->userHas($user, 'ledger.view')) {
            throw ApiException::forbidden();
        }
        $this->partyExists($type, $partyId);

        return [
            'balance' => Dec::fmt($this->ledger->balance($type, $partyId), Dec::MONEY),
            'documents' => array_map(fn ($d) => ['table' => $d['table'], 'id' => $d['id'], 'no' => $d['no'], 'date' => $d['date'], 'total' => Dec::fmt($d['total'], Dec::MONEY), 'open' => Dec::fmt($d['open'], Dec::MONEY)], $this->ledger->openDocuments($type, $partyId)),
        ];
    }

    /**
     * @param  array<string, mixed>  $in  party_type, party_id, method, amount, account_id?, references, payment_date?, auto_oldest_first?, allocations?, note?, attachment_id?, approvals?
     */
    public function record(User $user, array $in, ?string $idempotencyKey = null): PartyPayment
    {
        $type = (string) $in['party_type'];
        $partyId = (int) $in['party_id'];
        $direction = $type === 'customer' ? 'receive' : 'pay';
        if (! $this->permissions->userHas($user, self::permissionFor($type))) {
            throw ApiException::forbidden();
        }
        if ($idempotencyKey !== null && ($existing = PartyPayment::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        $method = (string) $in['method'];
        $amount = Dec::parse((string) $in['amount'], Dec::MONEY);
        if ($amount <= 0) {
            throw new ApiException('VALIDATION', 422, '', ['amount' => ['errors.REQUIRED']]);
        }
        $date = (string) ($in['payment_date'] ?? Branches::today());
        if ($date > Branches::today()) {
            throw new ApiException('VALIDATION', 422, '', ['payment_date' => ['errors.DATE_IN_FUTURE']]);
        }
        $this->period->assertOpen($date);

        $account = $this->accounts->resolve($method, isset($in['account_id']) ? (int) $in['account_id'] : null);
        $refs = $this->references->normalise($method, $in, '');
        $trxKey = null;
        $approver = null;
        if ($refs['trx_id'] !== null) {
            $state = $this->references->duplicateState($method, $refs['trx_id'], (int) $this->settings->get('duplicate_trx_days'));
            if ($state === 'recent') {
                if (trim((string) ($in['approvals']['manager_pin'] ?? '')) === '') {
                    throw new ApiException('DUPLICATE_TRXID', 409);
                }
                $approver = $this->approvals->approver($user, $in['approvals']['manager_pin'], ['payments.verify']);
            } elseif ($state === null) {
                $trxKey = $method.':'.$refs['trx_id'];
            }
        }
        $verification = $method !== 'cash' && (bool) $this->settings->get('require_verification_for_mfs') ? 'pending' : 'confirmed';
        $auto = (bool) ($in['auto_oldest_first'] ?? true);
        $requested = array_map(fn ($a) => ['table' => (string) $a['doc_table'], 'id' => (int) $a['doc_id'], 'amount' => Dec::parse((string) $a['amount'], Dec::MONEY)], (array) ($in['allocations'] ?? []));

        try {
            $payment = DB::transaction(function () use ($in, $type, $partyId, $direction, $method, $amount, $date, $account, $refs, $trxKey, $verification, $auto, $requested, $idempotencyKey, $approver): PartyPayment {
                $party = $this->ledger->lockParty($type, $partyId);
                if ($party instanceof Customer && $party->is_walk_in) {
                    throw new ApiException('WALKIN_PROTECTED', 422);
                }
                $this->accounts->lock([$account->id]);
                $docs = $this->ledger->openDocuments($type, $partyId, true);
                $split = $auto ? Allocator::oldestFirst($docs, $amount) : Allocator::manual($docs, $requested, $amount);
                if ($direction === 'pay') {
                    $this->accounts->assertCanSpend($account->id, $amount);
                }
                $allocated = $amount - $split['remainder'];
                $now = CarbonImmutable::now();

                $payment = PartyPayment::query()->create([
                    'branch_id' => Branches::currentId(), 'payment_no' => $this->numbers->next('party_payment', Branches::currentId(), CarbonImmutable::parse($date)),
                    'party_type' => $type, 'party_id' => $partyId, 'direction' => $direction, 'method' => $method, 'amount' => Dec::fmt($amount, Dec::MONEY), 'account_id' => $account->id,
                    'sender_number' => $refs['sender_number'], 'trx_id' => $refs['trx_id'], 'bank_name' => $refs['bank_name'], 'bank_ref' => $refs['bank_ref'],
                    'payment_date' => $date, 'allocated_total' => Dec::fmt($allocated, Dec::MONEY), 'advance_amount' => Dec::fmt($split['remainder'], Dec::MONEY),
                    'verification' => $verification, 'attachment_id' => $in['attachment_id'] ?? null, 'note' => $in['note'] ?? null,
                    'idempotency_key' => $idempotencyKey, 'trx_key' => $trxKey, 'status' => 'posted',
                ]);
                foreach ($split['allocations'] as $a) {
                    PartyPaymentAllocation::query()->create(['payment_id' => $payment->id, 'doc_table' => $a['table'], 'doc_id' => $a['id'], 'amount' => Dec::fmt($a['amount'], Dec::MONEY)]);
                }
                $this->applyToDocuments($split['allocations'], 1);

                $this->accounts->post($account->id, $direction === 'receive' ? 'in' : 'out', $amount, $date, 'party_payment', $payment->id, $payment->payment_no,
                    ($direction === 'receive' ? 'Payment received ' : 'Payment made ').$payment->payment_no, ['counterparty_type' => $type, 'counterparty_id' => $partyId, 'occurred_at' => $now]);
                $this->postLedger($type, $partyId, $payment, $allocated, $split['remainder'], $date, false);

                $this->audit->log('party_payment_record', 'party_payments', $payment->id, null, [
                    'payment_no' => $payment->payment_no, 'party_type' => $type, 'party_id' => $partyId, 'method' => $method, 'amount' => $payment->amount,
                    'allocated' => $payment->allocated_total, 'advance' => $payment->advance_amount, 'account_id' => $account->id,
                ], reason: $approver !== null ? ($in['approvals']['reason'] ?? null) : null, approverId: $approver?->id);

                return $payment;
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23000' && str_contains($e->getMessage(), 'idempotency_key') && $idempotencyKey !== null) {
                return PartyPayment::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }
            if (($e->errorInfo[0] ?? '') === '23000' && str_contains($e->getMessage(), 'trx_key')) {
                throw new ApiException('DUPLICATE_TRXID', 409);
            }
            throw $e;
        }

        return $this->withReceipt($payment->refresh());
    }

    /** Take a posted payment back out as if it never happened; the receipt and the books keep the trail (BR-051 in spirit). */
    public function void(User $user, PartyPayment $payment, string $reason): PartyPayment
    {
        $this->period->assertOpen($payment->payment_date->toDateString());

        DB::transaction(function () use ($payment, $reason): void {
            $locked = PartyPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'void') {
                throw new ApiException('ALREADY_VOID', 409);
            }
            $this->ledger->lockParty($locked->party_type, (int) $locked->party_id);
            $this->accounts->lock([(int) $locked->account_id]);
            $allocations = PartyPaymentAllocation::query()->where('payment_id', $locked->id)->get()->map(fn ($a) => ['table' => $a->doc_table, 'id' => (int) $a->doc_id, 'amount' => Dec::parse((string) $a->amount, Dec::MONEY)])->all();
            $this->lockDocuments($allocations);
            $this->applyToDocuments($allocations, -1);

            $amount = Dec::parse((string) $locked->amount, Dec::MONEY);
            $day = Branches::today();
            $reverseDirection = $locked->direction === 'receive' ? 'out' : 'in';
            if ($reverseDirection === 'out') {
                $this->accounts->assertCanSpend((int) $locked->account_id, $amount);
            }
            $this->accounts->post((int) $locked->account_id, $reverseDirection, $amount, $day, 'adjustment', $locked->id, $locked->payment_no, 'Void '.$locked->payment_no,
                ['counterparty_type' => $locked->party_type, 'counterparty_id' => (int) $locked->party_id]);
            $this->postLedger($locked->party_type, (int) $locked->party_id, $locked, Dec::parse((string) $locked->allocated_total, Dec::MONEY), Dec::parse((string) $locked->advance_amount, Dec::MONEY), $day, true);

            $locked->forceFill(['status' => 'void', 'void_reason' => $reason, 'voided_by' => Auth::id(), 'trx_key' => null])->save();
            $this->audit->log('party_payment_void', 'party_payments', $locked->id, ['status' => 'posted'], ['status' => 'void', 'payment_no' => $locked->payment_no, 'amount' => $locked->amount], $reason);
        }, 3);

        return $this->withReceipt($payment->refresh(), true);
    }

    /** payments.verify: a payment that was waiting to be checked against the MFS or bank statement is now confirmed. */
    public function confirm(PartyPayment $payment): PartyPayment
    {
        if ($payment->status === 'void') {
            throw new ApiException('ALREADY_VOID', 409);
        }
        if ($payment->verification !== 'pending') {
            throw new ApiException('INVALID_STATE', 409);
        }
        $payment->forceFill(['verification' => 'confirmed'])->save();
        $this->audit->log('party_payment_confirm', 'party_payments', $payment->id, ['verification' => 'pending'], ['verification' => 'confirmed', 'payment_no' => $payment->payment_no]);

        return $payment->refresh();
    }

    /**
     * Add (sign 1) or take back (sign −1) allocated amounts on the documents they settled.
     *
     * @param  array<int, array{table: string, id: int, amount: int}>  $allocations
     */
    private function applyToDocuments(array $allocations, int $sign): void
    {
        foreach ($allocations as $a) {
            $delta = $sign * $a['amount'];
            if ($a['table'] === 'sales') {
                $sale = DB::table('sales')->where('id', $a['id'])->first(['paid_total', 'due_total']);
                $paid = Dec::parse((string) $sale->paid_total, Dec::MONEY) + $delta;
                $due = Dec::parse((string) $sale->due_total, Dec::MONEY) - $delta;
                DB::table('sales')->where('id', $a['id'])->update(['paid_total' => Dec::fmt($paid, Dec::MONEY), 'due_total' => Dec::fmt($due, Dec::MONEY), 'payment_status' => $due === 0 ? 'paid' : ($paid === 0 ? 'unpaid' : 'partial')]);
            } elseif ($a['table'] === 'goods_receipts') {
                DB::table('goods_receipts')->where('id', $a['id'])->update(['paid_total' => DB::raw('paid_total + '.Dec::fmt($delta, Dec::MONEY))]);
            }
        }
    }

    /** @param  array<int, array{table: string, id: int, amount: int}>  $allocations */
    private function lockDocuments(array $allocations): void
    {
        foreach (['sales', 'goods_receipts'] as $table) {
            $ids = array_values(array_unique(array_map(fn ($a) => $a['id'], array_filter($allocations, fn ($a) => $a['table'] === $table))));
            if ($ids !== []) {
                sort($ids);
                DB::table($table)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            }
        }
    }

    /** A customer's payment is a credit and a supplier's a debit; a void posts the opposite side as one adjustment. */
    private function postLedger(string $type, int $partyId, PartyPayment $payment, int $allocated, int $advance, string $day, bool $reverse): void
    {
        $lines = $reverse
            ? [['adjustment', $allocated + $advance, 'Void '.$payment->payment_no]]
            : array_values(array_filter([['payment', $allocated, ($type === 'customer' ? 'Payment received ' : 'Payment made ').$payment->payment_no], ['advance', $advance, 'Advance kept from '.$payment->payment_no]], fn ($l) => $l[1] > 0));
        // Customer: money in = credit. Supplier: money out = debit. A void flips both.
        $creditSide = ($type === 'customer') !== $reverse;
        foreach ($lines as [$entryType, $paisa, $text]) {
            $this->ledger->post($type, $partyId, $entryType, $creditSide ? 0 : $paisa, $creditSide ? $paisa : 0, 'party_payments', $payment->id, $payment->payment_no, $text, $day, (int) $payment->branch_id);
        }
    }

    /** The receipt is made after the payment is safely committed; if it fails (for example the company profile is incomplete) the payment stands. */
    private function withReceipt(PartyPayment $payment, bool $force = false): PartyPayment
    {
        try {
            $this->pdf->ensureReceipt($payment, $force);
        } catch (\Throwable $e) {
            Log::warning('Payment receipt PDF failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        return $payment->refresh();
    }

    private function partyExists(string $type, int $id): void
    {
        $exists = $type === 'customer' ? DB::table('customers')->where('id', $id)->whereNull('deleted_at')->exists() : DB::table('suppliers')->where('id', $id)->whereNull('deleted_at')->exists();
        if (! $exists) {
            throw ApiException::notFound();
        }
    }
}
