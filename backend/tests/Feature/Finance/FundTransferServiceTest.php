<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ApiException;
use App\Models\Expense;
use App\Models\FundTransfer;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FundTransferService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** FR-1004: money between our own accounts, with the charge booked as an expense. */
class FundTransferServiceTest extends FinanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fund('bkash', '10000.00');
    }

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

    /** @param  array<string, mixed>  $over */
    private function move(string $amount, string $fee = '0', array $over = []): FundTransfer
    {
        $this->actingAsUser($this->accountant);

        return app(FundTransferService::class)->create($this->accountant, array_merge(['from_account_id' => $this->account('bkash')->id, 'to_account_id' => $this->account('bank')->id, 'amount' => $amount, 'fee_amount' => $fee], $over));
    }

    public function test_a_transfer_moves_the_money_and_books_the_charge_as_an_expense(): void
    {
        // 6,000 moves from bKash to the bank; the 90 charge is taken from bKash as well.
        $t = $this->move('6000.00', '90.00', ['note' => 'weekly deposit']);

        $this->assertMatchesRegularExpression('/^ORB-FT-\d{4}-\d{5}$/', $t->transfer_no);
        $this->assertSame('3910.00', $this->accountBalance('bkash'));
        $this->assertSame('6000.00', $this->accountBalance('bank'));
        $fee = Expense::query()->findOrFail($t->fee_expense_id);
        $this->assertSame(['90.00', 'posted', $this->account('bkash')->id], [$fee->amount, $fee->status, $fee->account_id]);
        $this->assertSame('transfer_fee', DB::table('expense_categories')->where('id', $fee->category_id)->value('code'));
        $this->assertDatabaseHas('account_transactions', ['ref_type' => 'fund_transfer', 'ref_id' => $t->id, 'direction' => 'out', 'amount' => '6000.00']);
        $this->assertDatabaseHas('account_transactions', ['ref_type' => 'fund_transfer', 'ref_id' => $t->id, 'direction' => 'in', 'amount' => '6000.00']);
        $this->assertBooksHold();
    }

    public function test_a_transfer_without_a_charge_makes_no_expense(): void
    {
        $t = $this->move('1000.00');
        $this->assertNull($t->fee_expense_id);
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_transfer_needs_two_different_accounts_and_enough_money_for_the_amount_and_the_charge(): void
    {
        $this->expectError('VALIDATION', fn () => $this->move('100.00', '0', ['to_account_id' => $this->account('bkash')->id]));
        $this->expectError('INSUFFICIENT_FUNDS', fn () => $this->move('10000.00', '1.00'));
        $this->expectError('VALIDATION', fn () => $this->move('0'));
        $this->expectError('VALIDATION', fn () => $this->move('100.00', '0', ['transfer_date' => now('Asia/Dhaka')->addDay()->toDateString()]));
        $this->assertSame(0, FundTransfer::query()->count());
        $this->assertSame('10000.00', $this->accountBalance('bkash'));
        $this->move('9999.00', '1.00');
        $this->assertSame('0.00', $this->accountBalance('bkash'));
    }

    public function test_a_void_reverses_both_legs_and_the_charge(): void
    {
        $t = $this->move('6000.00', '90.00');
        $this->actingAsUser($this->accountant);
        $v = app(FundTransferService::class)->void($this->accountant, $t, 'wrong account');
        $this->assertSame(['void', 'wrong account'], [$v->status, $v->void_reason]);
        $this->assertSame('10000.00', $this->accountBalance('bkash'));
        $this->assertSame('0.00', $this->accountBalance('bank'));
        $this->assertSame('void', Expense::query()->findOrFail($t->fee_expense_id)->status);
        $this->expectError('ALREADY_VOID', fn () => app(FundTransferService::class)->void($this->accountant, $v, 'again'));
        $this->assertBooksHold();
    }

    public function test_a_void_is_refused_when_the_receiving_account_has_spent_the_money(): void
    {
        $t = $this->move('6000.00');
        $this->actingAsUser($this->accountant);
        app(ExpenseService::class)->create($this->accountant, ['category_id' => DB::table('expense_categories')->value('id'), 'amount' => '5500.00', 'account_id' => $this->account('bank')->id]);
        $this->expectError('INSUFFICIENT_FUNDS', fn () => app(FundTransferService::class)->void($this->accountant, $t, 'oops'));
        $this->assertSame('posted', $t->refresh()->status);
    }

    public function test_the_charge_expense_cannot_be_voided_on_its_own(): void
    {
        $t = $this->move('1000.00', '20.00');
        $this->actingAsUser($this->accountant);
        $this->expectError('IS_TRANSFER_FEE', fn () => app(ExpenseService::class)->void($this->accountant, Expense::query()->findOrFail($t->fee_expense_id), 'no'));
    }

    public function test_a_locked_period_refuses_a_transfer_and_its_void(): void
    {
        $t = $this->move('100.00');
        app(SettingsService::class)->update(['period_lock_date' => now('Asia/Dhaka')->toDateString()]);
        $this->expectError('PERIOD_LOCKED', fn () => $this->move('100.00', '0', ['transfer_date' => now('Asia/Dhaka')->toDateString()]));
        $this->actingAsUser($this->accountant);
        $this->expectError('PERIOD_LOCKED', fn () => app(FundTransferService::class)->void($this->accountant, $t, 'late'));
    }
}
