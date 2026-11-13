<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\FinancialAccount;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-1003: the cash drawer, mobile-money wallets and bank accounts payments go into. Their balance is derived, never edited. */
class AccountService
{
    public function __construct(
        private readonly AccountLedgerService $ledger,
        private readonly PeriodLockService $period,
        private readonly AuditService $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): FinancialAccount
    {
        return DB::transaction(function () use ($data): FinancialAccount {
            $account = new FinancialAccount($data + ['branch_id' => Branches::currentId(), 'opening_date' => Branches::today()]);
            $first = ! FinancialAccount::query()->where('account_type', $account->account_type)->exists();
            $account->is_default_for_method = $first || (bool) ($data['is_default_for_method'] ?? false);
            $account->save();
            $this->singleDefault($account);

            return $account->refresh();
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function update(FinancialAccount $account, array $data): FinancialAccount
    {
        return DB::transaction(function () use ($account, $data): FinancialAccount {
            $locked = FinancialAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $hasEntries = DB::table('account_transactions')->where('account_id', $locked->id)->exists();
            if (isset($data['opening_balance']) && $hasEntries && Dec::parse((string) $data['opening_balance'], Dec::MONEY) !== Dec::parse((string) $locked->opening_balance, Dec::MONEY)) {
                throw new ApiException('OPENING_LOCKED', 422, '', ['opening_balance' => ['errors.OPENING_LOCKED']]);
            }
            if (isset($data['account_type']) && $data['account_type'] !== $locked->account_type) {
                throw new ApiException('ACCOUNT_TYPE_LOCKED', 422, '', ['account_type' => ['errors.ACCOUNT_TYPE_LOCKED']]);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $this->assertCanRetire($locked);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $data['is_default_for_method'] = false;
            }
            $locked->fill($data)->save();
            $this->singleDefault($locked);

            return $locked->refresh();
        }, 3);
    }

    public function delete(FinancialAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $locked = FinancialAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (DB::table('account_transactions')->where('account_id', $locked->id)->exists()) {
                throw new ApiException('IN_USE', 409);
            }
            $this->assertCanRetire($locked);
            $locked->delete();
        }, 3);
    }

    /** Book a count difference or a missed entry: a dated in or out with a reason (ref_type adjustment). */
    public function adjust(FinancialAccount $account, string $direction, string $amount, string $reason, ?string $date = null): void
    {
        $paisa = Dec::parse($amount, Dec::MONEY);
        $date ??= Branches::today();
        if ($paisa <= 0) {
            throw new ApiException('VALIDATION', 422, '', ['amount' => ['errors.REQUIRED']]);
        }
        $this->period->assertOpen($date);

        DB::transaction(function () use ($account, $direction, $paisa, $reason, $date): void {
            $this->ledger->lock([$account->id]);
            if ($direction === 'out') {
                $this->ledger->assertCanSpend($account->id, $paisa);
            }
            $row = $this->ledger->post($account->id, $direction, $paisa, $date, 'adjustment', null, null, 'Adjustment: '.$reason);
            $this->audit->log('account_adjust', 'financial_accounts', $account->id, null, ['direction' => $direction, 'amount' => Dec::fmt($paisa, Dec::MONEY), 'transaction_id' => $row->id], $reason);
        }, 3);
    }

    /** Only one account per type is the default for its payment method. */
    private function singleDefault(FinancialAccount $account): void
    {
        if ($account->is_default_for_method) {
            FinancialAccount::query()->where('account_type', $account->account_type)->where('id', '<>', $account->id)->update(['is_default_for_method' => false]);
        } elseif (! FinancialAccount::query()->where(['account_type' => $account->account_type, 'is_default_for_method' => true, 'is_active' => true])->exists()) {
            $next = FinancialAccount::query()->where(['account_type' => $account->account_type, 'is_active' => true])->orderBy('id')->first();
            $next?->forceFill(['is_default_for_method' => true])->save();
        }
    }

    /** A method with no active account cannot take a payment, and a drawer with an open shift cannot go away. */
    private function assertCanRetire(FinancialAccount $account): void
    {
        $others = FinancialAccount::query()->where(['account_type' => $account->account_type, 'is_active' => true])->where('id', '<>', $account->id)->exists();
        if (! $others) {
            throw new ApiException('LAST_ACCOUNT_OF_TYPE', 422);
        }
        if (DB::table('cash_shifts')->where(['cash_account_id' => $account->id, 'status' => 'open'])->whereNull('deleted_at')->exists()) {
            throw new ApiException('ACCOUNT_HAS_OPEN_SHIFT', 422);
        }
    }
}
