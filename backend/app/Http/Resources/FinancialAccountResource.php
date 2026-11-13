<?php

namespace App\Http\Resources;

use App\Models\FinancialAccount;
use App\Services\Finance\AccountLedgerService;
use App\Services\PermissionService;
use App\Support\Dec;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Names only for anyone who just needs to pick an account (cashiers); numbers, opening balance and balance need ledger.view
 * or accounts.manage.
 *
 * @mixin FinancialAccount
 */
class FinancialAccountResource extends JsonResource
{
    /** @var array<int, int>|null balances for the whole list, loaded once */
    public static ?array $balances = null;

    public function toArray(Request $request): array
    {
        $base = $this->only(['id', 'code', 'name', 'account_type', 'provider', 'is_default_for_method', 'is_active']);
        if (! app(PermissionService::class)->userHasAny($request->user(), ['ledger.view', 'accounts.manage'])) {
            return $base;
        }
        $balance = self::$balances[$this->id] ?? app(AccountLedgerService::class)->balance($this->id);

        return $base + [
            'account_number' => $this->account_number, 'bank_name' => $this->bank_name, 'bank_branch' => $this->bank_branch,
            'opening_balance' => $this->opening_balance, 'opening_date' => $this->opening_date?->toDateString(), 'note' => $this->note,
            'balance' => Dec::fmt($balance, Dec::MONEY),
        ];
    }
}
