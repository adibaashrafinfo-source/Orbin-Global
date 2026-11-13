<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ApiException;
use App\Models\PartyLedgerEntry;
use App\Models\SalePayment;
use App\Services\Finance\PartyPaymentService;
use App\Services\Sales\CustomerService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** FR-1002, BR-060 to BR-062 and AT-014: every figure is worked out by hand in the comments. */
class PartyPaymentServiceTest extends FinanceTestCase
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

    public function test_at014_two_and_a_half_invoices_are_paid_oldest_first(): void
    {
        $c = $this->customer();
        $one = $this->dueSale($c);
        $two = $this->dueSale($c);
        $three = $this->dueSale($c);
        $this->assertSame('3000.00', $this->customerBalance($c));

        // 2,500 settles invoice 1 (1,000) and 2 (1,000) and takes 500 off invoice 3, leaving 500 due there.
        $payment = $this->receive($c, '2500.00');

        $this->assertSame(['2500.00', '0.00'], [$payment->allocated_total, $payment->advance_amount]);
        $this->assertMatchesRegularExpression('/^ORB-PAY-\d{4}-\d{5}$/', $payment->payment_no);
        $this->assertSame(['paid', '0.00', '1000.00'], [$this->sale($one)->payment_status, $this->sale($one)->due_total, $this->sale($one)->paid_total]);
        $this->assertSame(['paid', '0.00', '1000.00'], [$this->sale($two)->payment_status, $this->sale($two)->due_total, $this->sale($two)->paid_total]);
        $this->assertSame(['partial', '500.00', '500.00'], [$this->sale($three)->payment_status, $this->sale($three)->due_total, $this->sale($three)->paid_total]);
        $this->assertSame([[$one->id, '1000.00'], [$two->id, '1000.00'], [$three->id, '500.00']], $payment->allocations()->orderBy('id')->get()->map(fn ($a) => [$a->doc_id, $a->amount])->all());
        $this->assertSame('500.00', $this->customerBalance($c));
        $this->assertSame('2500.00', $this->accountBalance('cash'));
        $this->assertDatabaseHas('account_transactions', ['ref_type' => 'party_payment', 'ref_id' => $payment->id, 'direction' => 'in', 'amount' => '2500.00', 'counterparty_type' => 'customer', 'counterparty_id' => $c->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'party_payment_record', 'entity_id' => $payment->id]);
        $this->assertBooksHold();
    }

    public function test_the_part_above_what_is_due_stays_as_an_advance(): void
    {
        $c = $this->customer();
        $this->dueSale($c, 2);

        // Due 2,000, paid 2,700: 2,000 allocated, 700 advance, so the customer is 700 in credit.
        $payment = $this->receive($c, '2700.00');

        $this->assertSame(['2000.00', '700.00'], [$payment->allocated_total, $payment->advance_amount]);
        $this->assertSame('-700.00', $this->customerBalance($c));
        $this->assertSame(['payment' => '2000.00', 'advance' => '700.00'], PartyLedgerEntry::query()->where('ref_table', 'party_payments')->where('ref_id', $payment->id)->pluck('credit', 'entry_type')->all());
        $this->assertBooksHold();
    }

    public function test_a_customer_with_nothing_due_can_still_leave_an_advance(): void
    {
        $c = $this->customer();
        $payment = $this->receive($c, '400.00');
        $this->assertSame(['0.00', '400.00'], [$payment->allocated_total, $payment->advance_amount]);
        $this->assertSame('-400.00', $this->customerBalance($c));
        $this->assertSame(0, $payment->allocations()->count());
    }

    public function test_a_manual_split_goes_where_the_user_says_and_never_above_what_is_due(): void
    {
        $c = $this->customer();
        $one = $this->dueSale($c);
        $two = $this->dueSale($c);

        $payment = $this->receive($c, '700.00', ['auto_oldest_first' => false, 'allocations' => [['doc_table' => 'sales', 'doc_id' => $two->id, 'amount' => '600.00']]]);

        $this->assertSame(['600.00', '100.00'], [$payment->allocated_total, $payment->advance_amount]);
        $this->assertSame('1000.00', $this->sale($one)->due_total);
        $this->assertSame('400.00', $this->sale($two)->due_total);

        $this->expectError('ALLOCATION_EXCEEDS_DUE', fn () => $this->receive($c, '900.00', ['auto_oldest_first' => false, 'allocations' => [['doc_table' => 'sales', 'doc_id' => $two->id, 'amount' => '500.00']]]));
        $this->expectError('ALLOCATION_EXCEEDS_AMOUNT', fn () => $this->receive($c, '300.00', ['auto_oldest_first' => false, 'allocations' => [['doc_table' => 'sales', 'doc_id' => $one->id, 'amount' => '200.00'], ['doc_table' => 'sales', 'doc_id' => $two->id, 'amount' => '200.00']]]));
        $this->expectError('ALLOCATION_EXCEEDS_DUE', fn () => $this->receive($c, '100.00', ['auto_oldest_first' => false, 'allocations' => [['doc_table' => 'sales', 'doc_id' => 99999999, 'amount' => '100.00']]]));
        $this->assertSame(1, DB::table('party_payments')->count(), 'refused payments left nothing behind');
        $this->assertBooksHold();
    }

    public function test_the_opening_balance_is_settled_before_any_invoice(): void
    {
        $c = $this->customer(['opening_balance' => '800.00']);
        $sale = $this->dueSale($c);
        $this->assertSame('1800.00', $this->customerBalance($c));

        // 1,000 clears the 800 brought forward first, then 200 goes to the invoice.
        $payment = $this->receive($c, '1000.00');

        $this->assertSame([['party_ledger_entries', '800.00'], ['sales', '200.00']], $payment->allocations()->orderBy('id')->get()->map(fn ($a) => [$a->doc_table, $a->amount])->all());
        $this->assertSame('800.00', $this->sale($sale)->due_total);
        $this->assertSame('800.00', $this->customerBalance($c));
        $docs = app(PartyPaymentService::class)->openDocuments($this->accountant, 'customer', $c->id)['documents'];
        $this->assertSame([['sales', '800.00']], array_map(fn ($d) => [$d['table'], $d['open']], $docs));
        $this->assertBooksHold();
    }

    public function test_the_credit_from_a_return_settles_that_invoice_first(): void
    {
        $c = $this->customer();
        $sale = $this->dueSale($c, 2);
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        app(SaleReturnService::class)->create($manager, $sale->refresh(), ['items' => [['sale_item_id' => $sale->items()->first()->id, 'qty' => '1', 'condition' => 'resellable']], 'refund_mode' => 'ledger_credit']);
        // Billed 2,000, one mirror came back for 1,000 of credit: the customer now owes 1,000 and the invoice can be allocated only that much.
        $this->assertSame('1000.00', $this->customerBalance($c));
        $docs = app(PartyPaymentService::class)->openDocuments($this->accountant, 'customer', $c->id)['documents'];
        $this->assertSame('1000.00', $docs[0]['open']);

        $this->expectError('ALLOCATION_EXCEEDS_DUE', fn () => $this->receive($c, '1500.00', ['auto_oldest_first' => false, 'allocations' => [['doc_table' => 'sales', 'doc_id' => $sale->id, 'amount' => '1500.00']]]));
        $payment = $this->receive($c, '1000.00');
        $this->assertSame('1000.00', $payment->allocated_total);
        $this->assertSame('0.00', $this->customerBalance($c));
        $this->assertSame([], app(PartyPaymentService::class)->openDocuments($this->accountant, 'customer', $c->id)['documents']);
        $this->assertBooksHold();
    }

    public function test_a_supplier_payment_settles_receipts_oldest_first_and_takes_money_out(): void
    {
        [$supplier, $old] = $this->supplierWithReceipt('10', '100', now('Asia/Dhaka')->subDays(20)->toDateString());
        [, $new] = [null, $this->supplierWithReceipt('5', '100')[1]];
        // The second helper call made another supplier; put its receipt under the first one instead.
        DB::table('goods_receipts')->where('id', $new->id)->update(['supplier_id' => $supplier->id]);
        DB::table('party_ledger_entries')->where('ref_table', 'goods_receipts')->where('ref_id', $new->id)->update(['party_id' => $supplier->id]);
        $this->assertSame('1500.00', $this->supplierBalance($supplier));
        $this->fund('cash', '5000.00');

        // 1,200 clears the older receipt (1,000) and takes 200 off the newer one (500).
        $payment = $this->pay($supplier, '1200.00');

        $this->assertSame('pay', $payment->direction);
        $this->assertSame([[$old->id, '1000.00'], [$new->id, '200.00']], $payment->allocations()->orderBy('id')->get()->map(fn ($a) => [$a->doc_id, $a->amount])->all());
        $this->assertSame(['1000.00', '200.00'], [DB::table('goods_receipts')->where('id', $old->id)->value('paid_total'), DB::table('goods_receipts')->where('id', $new->id)->value('paid_total')]);
        $this->assertSame('300.00', $this->supplierBalance($supplier));
        $this->assertSame('3800.00', $this->accountBalance('cash'));
        $this->assertDatabaseHas('party_ledger_entries', ['party_type' => 'supplier', 'party_id' => $supplier->id, 'entry_type' => 'payment', 'debit' => '1200.00']);
        $this->assertBooksHold();
    }

    public function test_only_the_right_permission_can_take_or_make_a_payment(): void
    {
        $c = $this->customer();
        [$supplier] = $this->supplierWithReceipt();
        $this->fund('cash', '5000.00');
        $this->expectError('FORBIDDEN', fn () => $this->pay($supplier, '100.00', [], $this->cashier));
        $this->expectError('FORBIDDEN', fn () => $this->receive($c, '100.00', [], $this->userWithRole('storekeeper')));
        $this->assertSame('cash', $this->receive($c, '100.00', [], $this->accountant)->method);
        $this->assertSame('cash', $this->pay($supplier, '100.00', [], $this->accountant)->method);
    }

    public function test_the_walk_in_customer_cannot_pay_a_due_and_a_customer_cannot_be_paid_as_a_supplier(): void
    {
        $this->expectError('WALKIN_PROTECTED', fn () => $this->receive($this->walkIn(), '100.00'));
        $this->expectError('NOT_FOUND', fn () => $this->receive($this->customer(), '100.00', ['party_id' => 99999999]));
    }

    public function test_mobile_money_and_bank_payments_need_their_references(): void
    {
        $c = $this->customer();
        $this->expectError('PAYMENT_REFERENCE_REQUIRED', fn () => $this->receive($c, '100.00', ['method' => 'bkash', 'sender_number' => '01712345678']));
        $this->expectError('PAYMENT_REFERENCE_REQUIRED', fn () => $this->receive($c, '100.00', ['method' => 'nagad', 'trx_id' => 'T1', 'sender_number' => '12345']));
        $this->expectError('PAYMENT_REFERENCE_REQUIRED', fn () => $this->receive($c, '100.00', ['method' => 'bank_transfer', 'bank_name' => 'City Bank']));
        $p = $this->receive($c, '100.00', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'abc123']);
        $this->assertSame('ABC123', $p->trx_id);
        $this->assertSame($this->account('bkash')->id, $p->account_id);
        $bank = $this->receive($c, '50.00', ['method' => 'bank_transfer', 'bank_name' => 'City Bank', 'bank_ref' => 'CHQ-77']);
        $this->assertSame($this->account('bank')->id, $bank->account_id);
    }

    public function test_a_trxid_used_before_is_refused_unless_a_manager_approves_it(): void
    {
        $c = $this->customer();
        $ref = ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'DUP-1'];
        $this->receive($c, '100.00', $ref);

        $this->expectError('DUPLICATE_TRXID', fn () => $this->receive($c, '100.00', $ref));
        $this->expectError('PIN_INVALID', fn () => $this->receive($c, '100.00', $ref + ['approvals' => ['manager_pin' => '0000']]));
        $again = $this->receive($c, '100.00', $ref + ['approvals' => ['manager_pin' => '2345', 'reason' => 'customer paid twice']]);
        $this->assertNotNull($again->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'party_payment_record', 'entity_id' => $again->id, 'reason' => 'customer paid twice']);
        $this->assertNotNull(DB::table('audit_logs')->where('entity_id', $again->id)->where('action', 'party_payment_record')->value('approver_id'));
    }

    public function test_a_trxid_already_used_at_the_till_counts_too(): void
    {
        $c = $this->customer();
        $this->checkout($this->payload([$this->variant => '1'], ['customer_id' => $c->id, 'payments' => [['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678', 'trx_id' => 'TILL-1']]]));
        $this->expectError('DUPLICATE_TRXID', fn () => $this->receive($c, '100.00', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'TILL-1']));

        // And the other way round: a TrxID taken by a later receipt is refused at the till.
        $this->receive($c, '100.00', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'LATER-1']);
        $this->expectError('DUPLICATE_TRXID', fn () => $this->checkout($this->payload([$this->variant => '1'], ['customer_id' => $c->id, 'payments' => [['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678', 'trx_id' => 'LATER-1']]])));
        $this->assertSame(1, SalePayment::query()->where('trx_id', 'TILL-1')->count());
    }

    public function test_a_trxid_older_than_the_window_may_be_used_again(): void
    {
        $c = $this->customer();
        $ref = ['method' => 'rocket', 'sender_number' => '01812345678', 'trx_id' => 'OLD-1'];
        $first = $this->receive($c, '100.00', $ref);
        DB::table('party_payments')->where('id', $first->id)->update(['created_at' => now()->subDays(45)]);
        $this->assertNotNull($this->receive($c, '100.00', $ref)->id);
    }

    public function test_a_locked_period_refuses_a_payment_dated_inside_it(): void
    {
        $c = $this->customer();
        $this->dueSale($c);
        app(SettingsService::class)->update(['period_lock_date' => now('Asia/Dhaka')->subDay()->toDateString()]);
        $this->expectError('PERIOD_LOCKED', fn () => $this->receive($c, '100.00', ['payment_date' => now('Asia/Dhaka')->subDay()->toDateString()]));
        $this->assertNotNull($this->receive($c, '100.00')->id, 'today is open');
        $this->expectError('VALIDATION', fn () => $this->receive($c, '100.00', ['payment_date' => now('Asia/Dhaka')->addDay()->toDateString()]));
    }

    public function test_an_account_cannot_be_taken_below_zero_unless_the_setting_allows_it(): void
    {
        [$supplier] = $this->supplierWithReceipt();
        $this->fund('bank', '300.00');
        $bank = ['method' => 'bank_transfer', 'bank_name' => 'City Bank', 'bank_ref' => 'CHQ-1'];
        $this->expectError('INSUFFICIENT_FUNDS', fn () => $this->pay($supplier, '500.00', $bank));
        $this->assertSame('300.00', $this->accountBalance('bank'));
        app(SettingsService::class)->update(['allow_negative_account_balance' => true]);
        $this->pay($supplier, '500.00', $bank);
        $this->assertSame('-200.00', $this->accountBalance('bank'));
    }

    public function test_the_same_idempotency_key_is_one_payment(): void
    {
        $c = $this->customer();
        $this->dueSale($c);
        $service = app(PartyPaymentService::class);
        $this->actingAsUser($this->cashier);
        $data = ['party_type' => 'customer', 'party_id' => $c->id, 'method' => 'cash', 'amount' => '400.00'];
        $a = $service->record($this->cashier, $data, 'key-1');
        $b = $service->record($this->cashier, $data, 'key-1');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, DB::table('party_payments')->count());
        $this->assertSame('400.00', $this->accountBalance('cash'));
    }

    public function test_payments_can_wait_for_verification_and_then_be_confirmed(): void
    {
        app(SettingsService::class)->update(['require_verification_for_mfs' => true]);
        $c = $this->customer();
        $pending = $this->receive($c, '100.00', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'PEND-1']);
        $this->assertSame('pending', $pending->verification);
        $this->assertSame('confirmed', $this->receive($c, '100.00')->verification, 'cash never waits');
        $this->actingAsUser($this->accountant);
        $this->assertSame('confirmed', app(PartyPaymentService::class)->confirm($pending)->verification);
        $this->expectError('INVALID_STATE', fn () => app(PartyPaymentService::class)->confirm($pending->refresh()));
    }

    public function test_a_void_puts_everything_back_and_frees_the_trxid(): void
    {
        $c = $this->customer();
        $one = $this->dueSale($c);
        $two = $this->dueSale($c);
        $payment = $this->receive($c, '1500.00', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'VOID-1']);
        $this->assertSame('500.00', $this->customerBalance($c));

        $this->actingAsUser($this->accountant);
        $voided = app(PartyPaymentService::class)->void($this->accountant, $payment, 'typed the wrong customer');

        $this->assertSame(['void', 'typed the wrong customer', null], [$voided->status, $voided->void_reason, $voided->trx_key]);
        $this->assertSame(['unpaid', '1000.00', '0.00'], [$this->sale($one)->payment_status, $this->sale($one)->due_total, $this->sale($one)->paid_total]);
        $this->assertSame(['unpaid', '1000.00', '0.00'], [$this->sale($two)->payment_status, $this->sale($two)->due_total, $this->sale($two)->paid_total]);
        $this->assertSame('2000.00', $this->customerBalance($c));
        $this->assertSame('0.00', $this->accountBalance('bkash'));
        $this->expectError('ALREADY_VOID', fn () => app(PartyPaymentService::class)->void($this->accountant, $voided, 'again'));
        $this->assertNotNull($this->receive($c, '100.00', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'VOID-1'])->id, 'the TrxID is free again');
        $this->assertBooksHold();
    }

    public function test_a_void_inside_a_locked_period_is_refused(): void
    {
        $c = $this->customer();
        $payment = $this->receive($c, '100.00');
        app(SettingsService::class)->update(['period_lock_date' => now('Asia/Dhaka')->toDateString()]);
        $this->actingAsUser($this->accountant);
        $this->expectError('PERIOD_LOCKED', fn () => app(PartyPaymentService::class)->void($this->accountant, $payment, 'too late'));
    }

    public function test_voiding_an_invoice_that_was_paid_later_keeps_that_money_as_an_advance(): void
    {
        $c = $this->customer();
        $sale = $this->dueSale($c);
        $this->receive($c, '1000.00');
        $this->actingAsUser($this->userWithRole('manager'));
        app(SaleService::class)->void($this->userWithRole('manager'), $sale, 'wrong customer');
        // The invoice is cancelled, but the 1,000 the customer paid is still theirs.
        $this->assertSame('-1000.00', $this->customerBalance($c));
        $this->assertSame('1000.00', $this->accountBalance('cash'));
        $this->assertBooksHold();
    }

    public function test_customer_service_balance_agrees_with_the_party_ledger(): void
    {
        $c = $this->customer(['opening_balance' => '250.00']);
        $this->dueSale($c, 2, '500.00');
        $this->receive($c, '700.00');
        $this->assertSame(app(CustomerService::class)->balance($c->id), $this->customerBalance($c));
        $this->assertSame('1050.00', $this->customerBalance($c));
    }
}
