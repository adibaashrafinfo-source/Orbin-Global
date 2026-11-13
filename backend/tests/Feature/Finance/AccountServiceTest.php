<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ApiException;
use App\Models\FinancialAccount;
use App\Services\Finance\AccountLedgerService;
use App\Services\Finance\AccountService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** FR-1003: accounts, their default per method, the opening balance rule and book adjustments. */
class AccountServiceTest extends FinanceTestCase
{
    private function expectError(string $code, callable $work): void
    {
        try {
            $work();
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);

            return;
        }
        $this->fail("expected {$code}");
    }

    public function test_the_first_account_of_a_type_is_its_default_and_a_new_default_replaces_it(): void
    {
        $this->actingAsUser($this->accountant);
        $old = $this->account('bank');
        $second = app(AccountService::class)->create(['code' => 'BANK-02', 'name' => 'Dutch-Bangla', 'account_type' => 'bank', 'opening_balance' => '500.00']);
        $this->assertFalse($second->is_default_for_method);
        $third = app(AccountService::class)->create(['code' => 'BANK-03', 'name' => 'City', 'account_type' => 'bank', 'is_default_for_method' => true]);
        $this->assertTrue($third->is_default_for_method);
        $this->assertFalse($old->refresh()->is_default_for_method);
        $this->assertSame(1, FinancialAccount::query()->where(['account_type' => 'bank', 'is_default_for_method' => true])->count());
        $this->assertSame($third->id, app(AccountLedgerService::class)->resolve('bank_transfer', null)->id);
    }

    public function test_the_opening_balance_can_change_only_while_the_account_is_unused(): void
    {
        $this->actingAsUser($this->accountant);
        $a = app(AccountService::class)->create(['code' => 'CASH-02', 'name' => 'Back counter', 'account_type' => 'cash', 'opening_balance' => '100.00']);
        $a = app(AccountService::class)->update($a, ['opening_balance' => '250.00']);
        $this->assertSame('250.00', $a->opening_balance);
        app(AccountService::class)->adjust($a, 'in', '10.00', 'found in the till');
        $this->expectError('OPENING_LOCKED', fn () => app(AccountService::class)->update($a, ['opening_balance' => '300.00']));
        $this->assertSame('Back counter 2', app(AccountService::class)->update($a, ['name' => 'Back counter 2', 'opening_balance' => '250.00'])->name);
        $this->expectError('ACCOUNT_TYPE_LOCKED', fn () => app(AccountService::class)->update($a, ['account_type' => 'bank']));
    }

    public function test_an_account_cannot_be_retired_while_it_is_the_last_one_of_its_method_or_has_an_open_shift(): void
    {
        $this->actingAsUser($this->accountant);
        $bank = $this->account('bank');
        $this->expectError('LAST_ACCOUNT_OF_TYPE', fn () => app(AccountService::class)->update($bank, ['is_active' => false]));
        $this->expectError('LAST_ACCOUNT_OF_TYPE', fn () => app(AccountService::class)->delete($bank));

        $cash2 = app(AccountService::class)->create(['code' => 'CASH-02', 'name' => 'Second drawer', 'account_type' => 'cash']);
        $this->expectError('ACCOUNT_HAS_OPEN_SHIFT', fn () => app(AccountService::class)->update($this->account('cash'), ['is_active' => false]));
        $this->assertFalse(app(AccountService::class)->update($cash2, ['is_active' => false])->is_active);
        $this->assertFalse($cash2->refresh()->is_default_for_method);
    }

    public function test_an_account_with_entries_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $this->actingAsUser($this->accountant);
        $spare = app(AccountService::class)->create(['code' => 'BANK-02', 'name' => 'Spare', 'account_type' => 'bank']);
        app(AccountService::class)->adjust($spare, 'in', '5.00', 'seed');
        $this->expectError('IN_USE', fn () => app(AccountService::class)->delete($spare));
        $empty = app(AccountService::class)->create(['code' => 'BANK-03', 'name' => 'Empty', 'account_type' => 'bank']);
        app(AccountService::class)->delete($empty);
        $this->assertSoftDeleted('financial_accounts', ['id' => $empty->id]);
    }

    public function test_a_book_adjustment_is_an_entry_with_a_reason_and_cannot_overdraw(): void
    {
        $this->actingAsUser($this->accountant);
        $bank = $this->account('bank');
        app(AccountService::class)->adjust($bank, 'in', '700.00', 'statement shows a deposit we missed');
        $this->assertSame('700.00', $this->accountBalance('bank'));
        $this->expectError('INSUFFICIENT_FUNDS', fn () => app(AccountService::class)->adjust($bank, 'out', '700.01', 'x'));
        app(AccountService::class)->adjust($bank, 'out', '200.00', 'bank fee not yet booked');
        $this->assertSame('500.00', $this->accountBalance('bank'));
        $this->assertSame(2, DB::table('account_transactions')->where(['account_id' => $bank->id, 'ref_type' => 'adjustment'])->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_adjust', 'reason' => 'bank fee not yet booked']);
        app(SettingsService::class)->update(['period_lock_date' => now('Asia/Dhaka')->toDateString()]);
        $this->expectError('PERIOD_LOCKED', fn () => app(AccountService::class)->adjust($bank, 'in', '1.00', 'x', now('Asia/Dhaka')->toDateString()));
        $this->assertBooksHold();
    }
}
