<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ApiException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Finance\ExpenseService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** FR-1005: expenses post out of an account, wait for approval above the threshold, and are corrected only by a void. */
class ExpenseServiceTest extends FinanceTestCase
{
    private int $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rent = (int) ExpenseCategory::query()->where('name_en', 'Shop rent')->value('id');
        $this->fund('bank', '100000.00');
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
    private function spend(string $amount, array $over = [], string $role = 'accountant'): Expense
    {
        $user = $role === 'accountant' ? $this->accountant : $this->userWithRole($role);
        $this->actingAsUser($user);

        return app(ExpenseService::class)->create($user, array_merge(['category_id' => $this->rent, 'amount' => $amount, 'account_id' => $this->account('bank')->id, 'payee' => 'Landlord'], $over));
    }

    public function test_a_posted_expense_takes_the_money_out_on_its_date(): void
    {
        $e = $this->spend('25000.00', ['expense_date' => now('Asia/Dhaka')->subDays(2)->toDateString()]);
        $this->assertSame('posted', $e->status);
        $this->assertMatchesRegularExpression('/^ORB-EXP-\d{4}-\d{5}$/', $e->expense_no);
        $this->assertSame('75000.00', $this->accountBalance('bank'));
        $this->assertDatabaseHas('account_transactions', ['ref_type' => 'expense', 'ref_id' => $e->id, 'direction' => 'out', 'amount' => '25000.00', 'txn_date' => now('Asia/Dhaka')->subDays(2)->toDateString()]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense_create', 'entity_id' => $e->id]);
        $this->assertBooksHold();
    }

    public function test_an_expense_needs_an_active_category_a_date_not_in_the_future_and_funds(): void
    {
        $dead = ExpenseCategory::query()->create(['name_en' => 'Old', 'is_active' => false]);
        $this->expectError('VALIDATION', fn () => $this->spend('10.00', ['category_id' => $dead->id]));
        $this->expectError('VALIDATION', fn () => $this->spend('10.00', ['expense_date' => now('Asia/Dhaka')->addDay()->toDateString()]));
        $this->expectError('VALIDATION', fn () => $this->spend('0'));
        $this->expectError('INSUFFICIENT_FUNDS', fn () => $this->spend('100000.01'));
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_an_expense_over_the_threshold_waits_for_someone_else_to_approve_it(): void
    {
        app(SettingsService::class)->update(['expense_approval_threshold' => 10000]);
        $small = $this->spend('9999.99');
        $this->assertSame('posted', $small->status);
        $big = $this->spend('10000.00');
        $this->assertSame('pending_approval', $big->status);
        $this->assertSame('90000.01', $this->accountBalance('bank'), 'a pending expense has not touched the account');

        $this->actingAsUser($this->accountant);
        $this->expectError('SELF_APPROVAL', fn () => app(ExpenseService::class)->approve($this->accountant, $big));
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $approved = app(ExpenseService::class)->approve($manager, $big);
        $this->assertSame(['posted', $manager->id], [$approved->status, $approved->approved_by]);
        $this->assertSame('80000.01', $this->accountBalance('bank'));
        $this->expectError('INVALID_STATE', fn () => app(ExpenseService::class)->approve($manager, $approved));
        $this->assertBooksHold();
    }

    public function test_the_owner_may_approve_their_own_expense(): void
    {
        app(SettingsService::class)->update(['expense_approval_threshold' => 100]);
        $owner = $this->userWithRole('super_admin');
        $this->actingAsUser($owner);
        $e = app(ExpenseService::class)->create($owner, ['category_id' => $this->rent, 'amount' => '500.00', 'account_id' => $this->account('bank')->id]);
        $this->assertSame('pending_approval', $e->status);
        $this->assertSame('posted', app(ExpenseService::class)->approve($owner, $e)->status);
    }

    public function test_a_rejected_expense_never_touches_an_account(): void
    {
        app(SettingsService::class)->update(['expense_approval_threshold' => 100]);
        $e = $this->spend('500.00');
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $r = app(ExpenseService::class)->reject($manager, $e, 'not our cost');
        $this->assertSame(['rejected', 'not our cost'], [$r->status, $r->decision_note]);
        $this->assertSame('100000.00', $this->accountBalance('bank'));
        $this->expectError('INVALID_STATE', fn () => app(ExpenseService::class)->approve($manager, $r));
        $this->assertSame(0, DB::table('account_transactions')->where('ref_type', 'expense')->count());
    }

    public function test_a_void_gives_the_money_back_and_keeps_the_record(): void
    {
        $e = $this->spend('2000.00');
        $this->actingAsUser($this->accountant);
        $v = app(ExpenseService::class)->void($this->accountant, $e, 'entered twice');
        $this->assertSame(['void', 'entered twice'], [$v->status, $v->decision_note]);
        $this->assertSame('100000.00', $this->accountBalance('bank'));
        $this->assertDatabaseHas('account_transactions', ['ref_type' => 'adjustment', 'ref_id' => $e->id, 'direction' => 'in', 'amount' => '2000.00']);
        $this->expectError('ALREADY_VOID', fn () => app(ExpenseService::class)->void($this->accountant, $v, 'again'));
        $this->assertBooksHold();
    }

    public function test_a_locked_period_refuses_to_create_approve_or_void(): void
    {
        $e = $this->spend('100.00');
        app(SettingsService::class)->update(['period_lock_date' => now('Asia/Dhaka')->toDateString()]);
        $this->expectError('PERIOD_LOCKED', fn () => $this->spend('100.00', ['expense_date' => now('Asia/Dhaka')->toDateString()]));
        $this->actingAsUser($this->accountant);
        $this->expectError('PERIOD_LOCKED', fn () => app(ExpenseService::class)->void($this->accountant, $e, 'too late'));
    }
}
