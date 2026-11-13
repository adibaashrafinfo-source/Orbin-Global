<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FundTransfer;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Services\NumberService;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** FR-1004: money moved between our own accounts (bKash to bank, drawer to bank). A charge is booked as an expense on the source account. */
class FundTransferService
{
    public function __construct(
        private readonly AccountLedgerService $accounts,
        private readonly ExpenseService $expenses,
        private readonly NumberService $numbers,
        private readonly PeriodLockService $period,
        private readonly AuditService $audit,
    ) {}

    /** @param  array<string, mixed>  $in  from_account_id, to_account_id, amount, fee_amount?, transfer_date?, note? */
    public function create(User $user, array $in): FundTransfer
    {
        $from = (int) $in['from_account_id'];
        $to = (int) $in['to_account_id'];
        if ($from === $to) {
            throw new ApiException('VALIDATION', 422, '', ['to_account_id' => ['errors.SAME_ACCOUNT']]);
        }
        $amount = Dec::parse((string) $in['amount'], Dec::MONEY);
        $fee = Dec::parse((string) ($in['fee_amount'] ?? '0'), Dec::MONEY);
        if ($amount <= 0 || $fee < 0) {
            throw new ApiException('VALIDATION', 422, '', ['amount' => ['errors.REQUIRED']]);
        }
        $date = (string) ($in['transfer_date'] ?? Branches::today());
        if ($date > Branches::today()) {
            throw new ApiException('VALIDATION', 422, '', ['transfer_date' => ['errors.DATE_IN_FUTURE']]);
        }
        $this->period->assertOpen($date);

        return DB::transaction(function () use ($in, $from, $to, $amount, $fee, $date): FundTransfer {
            $accounts = $this->accounts->lock([$from, $to]);
            foreach ($accounts as $a) {
                if (! $a->is_active) {
                    throw new ApiException('VALIDATION', 422, '', ['account_id' => ['errors.ACCOUNT_REQUIRED']]);
                }
            }
            $this->accounts->assertCanSpend($from, $amount + $fee);
            $branch = Branches::currentId();
            $transfer = FundTransfer::query()->create([
                'branch_id' => $branch, 'transfer_no' => $this->numbers->next('fund_transfer', $branch, CarbonImmutable::parse($date)), 'from_account_id' => $from, 'to_account_id' => $to,
                'amount' => Dec::fmt($amount, Dec::MONEY), 'fee_amount' => Dec::fmt($fee, Dec::MONEY), 'transfer_date' => $date, 'note' => $in['note'] ?? null, 'status' => 'posted',
            ]);
            $this->accounts->post($from, 'out', $amount, $date, 'fund_transfer', $transfer->id, $transfer->transfer_no, 'Transfer to '.$accounts[$to]->name);
            $this->accounts->post($to, 'in', $amount, $date, 'fund_transfer', $transfer->id, $transfer->transfer_no, 'Transfer from '.$accounts[$from]->name);
            if ($fee > 0) {
                $category = ExpenseCategory::query()->where('code', ExpenseCategory::TRANSFER_FEE)->first();
                if ($category === null) {
                    throw new ApiException('TRANSFER_FEE_CATEGORY_MISSING', 422);
                }
                $expense = Expense::query()->create([
                    'branch_id' => $branch, 'expense_no' => $this->numbers->next('expense', $branch, CarbonImmutable::parse($date)), 'category_id' => $category->id, 'amount' => Dec::fmt($fee, Dec::MONEY),
                    'account_id' => $from, 'expense_date' => $date, 'note' => 'Charge on '.$transfer->transfer_no, 'status' => 'posted',
                ]);
                $this->expenses->postFee($expense);
                $transfer->forceFill(['fee_expense_id' => $expense->id])->save();
            }
            $this->audit->log('fund_transfer_create', 'fund_transfers', $transfer->id, null, ['transfer_no' => $transfer->transfer_no, 'from' => $from, 'to' => $to, 'amount' => $transfer->amount, 'fee' => $transfer->fee_amount]);

            return $transfer;
        }, 3)->refresh();
    }

    /** Both legs and the fee are reversed. The receiving account must still hold the money, or the void is refused. */
    public function void(User $user, FundTransfer $transfer, string $reason): FundTransfer
    {
        $this->period->assertOpen($transfer->transfer_date->toDateString());
        DB::transaction(function () use ($user, $transfer, $reason): void {
            $locked = FundTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'void') {
                throw new ApiException('ALREADY_VOID', 409);
            }
            $this->accounts->lock([(int) $locked->from_account_id, (int) $locked->to_account_id]);
            $amount = Dec::parse((string) $locked->amount, Dec::MONEY);
            $this->accounts->assertCanSpend((int) $locked->to_account_id, $amount);
            $day = Branches::today();
            $this->accounts->post((int) $locked->to_account_id, 'out', $amount, $day, 'adjustment', $locked->id, $locked->transfer_no, 'Void '.$locked->transfer_no);
            $this->accounts->post((int) $locked->from_account_id, 'in', $amount, $day, 'adjustment', $locked->id, $locked->transfer_no, 'Void '.$locked->transfer_no);
            if ($locked->fee_expense_id !== null) {
                $fee = Expense::query()->whereKey($locked->fee_expense_id)->lockForUpdate()->firstOrFail();
                $this->expenses->reverse($fee);
                $fee->forceFill(['status' => 'void', 'decision_note' => mb_substr($reason, 0, 255), 'voided_by' => $user->id])->save();
            }
            $locked->forceFill(['status' => 'void', 'void_reason' => mb_substr($reason, 0, 255), 'voided_by' => $user->id])->save();
            $this->audit->log('fund_transfer_void', 'fund_transfers', $locked->id, ['status' => 'posted'], ['status' => 'void', 'transfer_no' => $locked->transfer_no, 'amount' => $locked->amount], $reason);
        }, 3);

        return $transfer->refresh();
    }
}
