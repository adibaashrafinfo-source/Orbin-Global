<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Services\NumberService;
use App\Services\SettingsService;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FR-1005: money the business spends. An expense at or above the approval threshold waits for someone else to approve
 * it and touches no account until then. A posted expense is corrected by voiding it, never by editing.
 */
class ExpenseService
{
    public function __construct(
        private readonly AccountLedgerService $accounts,
        private readonly NumberService $numbers,
        private readonly PeriodLockService $period,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    /** @param  array<string, mixed>  $in  category_id, amount, account_id, expense_date?, payee?, note?, attachment_id? */
    public function create(User $user, array $in): Expense
    {
        $paisa = Dec::parse((string) $in['amount'], Dec::MONEY);
        if ($paisa <= 0) {
            throw new ApiException('VALIDATION', 422, '', ['amount' => ['errors.REQUIRED']]);
        }
        $date = (string) ($in['expense_date'] ?? Branches::today());
        if ($date > Branches::today()) {
            throw new ApiException('VALIDATION', 422, '', ['expense_date' => ['errors.DATE_IN_FUTURE']]);
        }
        $this->period->assertOpen($date);
        if (! ExpenseCategory::query()->whereKey((int) $in['category_id'])->where('is_active', true)->exists()) {
            throw new ApiException('VALIDATION', 422, '', ['category_id' => ['errors.REQUIRED']]);
        }
        $threshold = (int) $this->settings->get('expense_approval_threshold') * 100;
        $pending = $threshold > 0 && $paisa >= $threshold;

        return DB::transaction(function () use ($in, $paisa, $date, $pending): Expense {
            $account = $this->activeAccount((int) $in['account_id']);
            if (! $pending) {
                $this->accounts->assertCanSpend($account->id, $paisa);
            }
            $expense = Expense::query()->create([
                'branch_id' => Branches::currentId(), 'expense_no' => $this->numbers->next('expense', Branches::currentId(), CarbonImmutable::parse($date)),
                'category_id' => (int) $in['category_id'], 'amount' => Dec::fmt($paisa, Dec::MONEY), 'account_id' => $account->id, 'expense_date' => $date,
                'payee' => $in['payee'] ?? null, 'note' => $in['note'] ?? null, 'attachment_id' => $in['attachment_id'] ?? null,
                'status' => $pending ? 'pending_approval' : 'posted',
            ]);
            if (! $pending) {
                $this->postOut($expense);
            }
            $this->audit->log('expense_create', 'expenses', $expense->id, null, ['expense_no' => $expense->expense_no, 'amount' => $expense->amount, 'status' => $expense->status, 'account_id' => $account->id]);

            return $expense;
        }, 3)->refresh();
    }

    /** The person who entered it cannot approve it, except the owner (super_admin). */
    public function approve(User $user, Expense $expense): Expense
    {
        DB::transaction(function () use ($user, $expense): void {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending_approval') {
                throw new ApiException('INVALID_STATE', 409);
            }
            if ($locked->created_by === $user->id && ! $user->isSuperAdmin()) {
                throw new ApiException('SELF_APPROVAL', 403);
            }
            $this->period->assertOpen($locked->expense_date->toDateString());
            $account = $this->activeAccount((int) $locked->account_id);
            $this->accounts->assertCanSpend($account->id, Dec::parse((string) $locked->amount, Dec::MONEY));
            $locked->forceFill(['status' => 'posted', 'approved_by' => $user->id, 'decided_at' => now()])->save();
            $this->postOut($locked);
            $this->audit->log('expense_approve', 'expenses', $locked->id, ['status' => 'pending_approval'], ['status' => 'posted', 'expense_no' => $locked->expense_no, 'amount' => $locked->amount], approverId: $user->id);
        }, 3);

        return $expense->refresh();
    }

    public function reject(User $user, Expense $expense, string $reason): Expense
    {
        DB::transaction(function () use ($user, $expense, $reason): void {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending_approval') {
                throw new ApiException('INVALID_STATE', 409);
            }
            $locked->forceFill(['status' => 'rejected', 'approved_by' => $user->id, 'decided_at' => now(), 'decision_note' => mb_substr($reason, 0, 255)])->save();
            $this->audit->log('expense_reject', 'expenses', $locked->id, ['status' => 'pending_approval'], ['status' => 'rejected', 'expense_no' => $locked->expense_no], $reason);
        }, 3);

        return $expense->refresh();
    }

    /** Put a posted expense back: the money returns to the account as an adjustment entry and the expense stays on record as void. */
    public function void(User $user, Expense $expense, string $reason): Expense
    {
        $this->period->assertOpen($expense->expense_date->toDateString());
        DB::transaction(function () use ($user, $expense, $reason): void {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'void') {
                throw new ApiException('ALREADY_VOID', 409);
            }
            if ($locked->status !== 'posted') {
                throw new ApiException('INVALID_STATE', 409);
            }
            if (DB::table('fund_transfers')->where('fee_expense_id', $locked->id)->whereNull('deleted_at')->exists()) {
                throw new ApiException('IS_TRANSFER_FEE', 422);
            }
            $this->accounts->lock([(int) $locked->account_id]);
            $this->reverse($locked);
            $locked->forceFill(['status' => 'void', 'decision_note' => mb_substr($reason, 0, 255), 'voided_by' => $user->id])->save();
            $this->audit->log('expense_void', 'expenses', $locked->id, ['status' => 'posted'], ['status' => 'void', 'expense_no' => $locked->expense_no, 'amount' => $locked->amount], $reason);
        }, 3);

        return $expense->refresh();
    }

    /** Used by fund transfers for their fee: always posted, never waits for approval. Call inside the transfer's transaction with the account locked. */
    public function postFee(Expense $expense): void
    {
        $this->postOut($expense);
    }

    /** The reversal of an expense row, used by void and by a voided transfer's fee. Call inside a transaction with the account locked. */
    public function reverse(Expense $expense): void
    {
        $this->accounts->post((int) $expense->account_id, 'in', Dec::parse((string) $expense->amount, Dec::MONEY), Branches::today(), 'adjustment', $expense->id, $expense->expense_no, 'Void '.$expense->expense_no);
    }

    private function postOut(Expense $expense): void
    {
        $this->accounts->post((int) $expense->account_id, 'out', Dec::parse((string) $expense->amount, Dec::MONEY), $expense->expense_date->toDateString(), 'expense', $expense->id, $expense->expense_no, 'Expense '.$expense->expense_no);
    }

    private function activeAccount(int $id): FinancialAccount
    {
        $account = $this->accounts->lock([$id])[$id];
        if (! $account->is_active) {
            throw new ApiException('VALIDATION', 422, '', ['account_id' => ['errors.ACCOUNT_REQUIRED']]);
        }

        return $account;
    }
}
