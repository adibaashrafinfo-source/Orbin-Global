<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\FinancialAccount;
use App\Services\SettingsService;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Financial account balances and the one place new account entries are written by the finance services.
 * Balance = opening balance + Σ in − Σ out (FR-1003). Amounts are paisa.
 */
class AccountLedgerService
{
    /** Payment method → the account type that receives it. */
    public const TYPE_OF_METHOD = ['cash' => 'cash', 'bkash' => 'bkash', 'nagad' => 'nagad', 'rocket' => 'rocket', 'bank_transfer' => 'bank'];

    public function __construct(private readonly SettingsService $settings) {}

    /** The account for a payment method: the one asked for, else the method's default. It must be active and of the matching type. */
    public function resolve(string $method, ?int $accountId, string $field = 'account_id'): FinancialAccount
    {
        $type = self::TYPE_OF_METHOD[$method] ?? null;
        if ($type === null) {
            throw new ApiException('VALIDATION', 422, '', [$field => ['errors.ACCOUNT_REQUIRED']]);
        }
        $q = FinancialAccount::query()->where(['account_type' => $type, 'is_active' => true]);
        $account = $accountId
            ? (clone $q)->whereKey($accountId)->first()
            : ((clone $q)->where('is_default_for_method', true)->orderBy('id')->first() ?? (clone $q)->orderBy('id')->first());
        if ($account === null) {
            throw new ApiException('VALIDATION', 422, '', [$field => ['errors.ACCOUNT_REQUIRED']]);
        }

        return $account;
    }

    public function balance(int $accountId, ?string $asOf = null): int
    {
        $account = DB::table('financial_accounts')->where('id', $accountId)->first(['opening_balance']);
        if ($account === null) {
            return 0;
        }
        $row = DB::table('account_transactions')->where('account_id', $accountId)->when($asOf !== null, fn ($q) => $q->where('txn_date', '<=', $asOf))
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as i, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as o")->first();

        return Dec::parse((string) $account->opening_balance, Dec::MONEY) + Dec::parse((string) $row->i, Dec::MONEY) - Dec::parse((string) $row->o, Dec::MONEY);
    }

    /** @return array<int, int> balance in paisa for every account, in two queries */
    public function balances(?string $asOf = null): array
    {
        $moves = DB::table('account_transactions')->when($asOf !== null, fn ($q) => $q->where('txn_date', '<=', $asOf))->groupBy('account_id')
            ->selectRaw("account_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) as net")->pluck('net', 'account_id');
        $out = [];
        foreach (DB::table('financial_accounts')->whereNull('deleted_at')->get(['id', 'opening_balance']) as $a) {
            $out[(int) $a->id] = Dec::parse((string) $a->opening_balance, Dec::MONEY) + Dec::parse((string) ($moves[$a->id] ?? '0'), Dec::MONEY);
        }

        return $out;
    }

    /**
     * The account's mini ledger, newest last, each row with the balance after it.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function transactions(int $accountId, ?string $from, ?string $to, int $page, int $perPage): array
    {
        $opening = (string) DB::table('financial_accounts')->where('id', $accountId)->value('opening_balance');
        $inner = DB::table('account_transactions')->where('account_id', $accountId)
            ->selectRaw('id, txn_date, occurred_at, direction, amount, ref_type, ref_id, ref_no, description, counterparty_type, counterparty_id, ? + SUM(CASE WHEN direction = \'in\' THEN amount ELSE -amount END) OVER (ORDER BY txn_date, occurred_at, id) AS running', [$opening]);
        $q = DB::query()->fromSub($inner, 't')->when($from !== null, fn ($qq) => $qq->where('txn_date', '>=', $from))->when($to !== null, fn ($qq) => $qq->where('txn_date', '<=', $to));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('txn_date')->orderByDesc('occurred_at')->orderByDesc('id')->forPage($page, $perPage)->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'date' => (string) $r->txn_date, 'direction' => $r->direction, 'amount' => Dec::fmt(Dec::parse((string) $r->amount, Dec::MONEY), Dec::MONEY),
            'ref_type' => $r->ref_type, 'ref_id' => $r->ref_id !== null ? (int) $r->ref_id : null, 'ref_no' => $r->ref_no, 'description' => $r->description,
            'party_type' => $r->counterparty_type, 'party_id' => $r->counterparty_id !== null ? (int) $r->counterparty_id : null, 'balance' => Dec::fmt(Dec::parse((string) $r->running, Dec::MONEY), Dec::MONEY),
        ])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Lock the accounts in id order so two money movements touching the same pair of accounts can never deadlock.
     *
     * @param  array<int, int>  $ids
     * @return array<int, FinancialAccount> keyed by id
     */
    public function lock(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $rows = FinancialAccount::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($ids as $id) {
            if (! isset($rows[$id])) {
                throw new ApiException('VALIDATION', 422, '', ['account_id' => ['errors.ACCOUNT_REQUIRED']]);
            }
        }

        return $rows->all();
    }

    /** Call after lock(): an account may not be taken below zero unless the setting says so (the check and the write share the lock). */
    public function assertCanSpend(int $accountId, int $paisa): void
    {
        if ((bool) $this->settings->get('allow_negative_account_balance')) {
            return;
        }
        $balance = $this->balance($accountId);
        if ($balance < $paisa) {
            throw new ApiException('INSUFFICIENT_FUNDS', 422, '', ['account_id' => ['errors.INSUFFICIENT_FUNDS']], ['balance' => Dec::fmt($balance, Dec::MONEY), 'needed' => Dec::fmt($paisa, Dec::MONEY)]);
        }
    }

    /**
     * @param  array{counterparty_type?: string|null, counterparty_id?: int|null, created_by?: int|null, occurred_at?: CarbonInterface|null, branch_id?: int|null}  $extra
     */
    public function post(int $accountId, string $direction, int $paisa, string $date, string $refType, ?int $refId, ?string $refNo, string $description, array $extra = []): AccountTransaction
    {
        return AccountTransaction::query()->create([
            'created_by' => $extra['created_by'] ?? null,
            'branch_id' => $extra['branch_id'] ?? Branches::currentId(),
            'account_id' => $accountId, 'direction' => $direction, 'amount' => Dec::fmt($paisa, Dec::MONEY),
            'txn_date' => $date, 'occurred_at' => $extra['occurred_at'] ?? now(),
            'ref_type' => $refType, 'ref_id' => $refId, 'ref_no' => $refNo,
            'counterparty_type' => $extra['counterparty_type'] ?? null, 'counterparty_id' => $extra['counterparty_id'] ?? null,
            'description' => $description,
        ]);
    }
}
