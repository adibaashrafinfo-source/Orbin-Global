<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ApiException;
use App\Services\Finance\PartyAdjustmentService;
use App\Services\Finance\PartyLedgerService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** FR-1008 and the ledger adjustment: a due forgiven or discounted, a balance corrected, each with a reason and an audit row. */
class PartyAdjustmentServiceTest extends FinanceTestCase
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

    public function test_a_write_off_clears_the_oldest_dues_and_keeps_the_invoice_identity(): void
    {
        $c = $this->customer();
        $one = $this->dueSale($c);
        $two = $this->dueSale($c);
        $this->actingAsUser($this->accountant);

        // Forgive 1,500: invoice 1 in full, 500 of invoice 2.
        $done = app(PartyAdjustmentService::class)->writeOff('customer', $c->id, '1500.00', 'writeoff', 'garage closed down');

        $this->assertSame(['1000.00', '500.00'], array_column($done, 'amount'));
        $this->assertSame(['paid', '0.00', '0.00', '1000.00'], [$this->sale($one)->payment_status, $this->sale($one)->due_total, $this->sale($one)->paid_total, $this->sale($one)->written_off_total]);
        $this->assertSame(['partial', '500.00', '0.00', '500.00'], [$this->sale($two)->payment_status, $this->sale($two)->due_total, $this->sale($two)->paid_total, $this->sale($two)->written_off_total]);
        $this->assertSame('500.00', $this->customerBalance($c));
        $this->assertSame(2, DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $c->id, 'entry_type' => 'writeoff'])->where('ref_table', 'sales')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'party_writeoff', 'reason' => 'garage closed down']);
        $this->assertBooksHold();

        // What is left can still be collected, and settles the invoice.
        $this->receive($c, '500.00');
        $this->assertSame(['paid', '0.00', '500.00'], [$this->sale($two)->payment_status, $this->sale($two)->due_total, $this->sale($two)->paid_total]);
        $this->assertSame('0.00', $this->customerBalance($c));
        $this->assertBooksHold();
    }

    public function test_more_than_is_due_cannot_be_written_off(): void
    {
        $c = $this->customer();
        $this->dueSale($c);
        $this->expectError('ALLOCATION_EXCEEDS_DUE', fn () => app(PartyAdjustmentService::class)->writeOff('customer', $c->id, '1000.01', 'writeoff', 'too much'));
        $this->assertSame('1000.00', $this->customerBalance($c));
    }

    public function test_a_discount_is_booked_as_a_discount(): void
    {
        $c = $this->customer();
        $this->dueSale($c);
        app(PartyAdjustmentService::class)->writeOff('customer', $c->id, '100.00', 'discount', 'loyal customer');
        $this->assertDatabaseHas('party_ledger_entries', ['party_id' => $c->id, 'entry_type' => 'discount', 'credit' => '100.00']);
        $this->assertSame('900.00', $this->customerBalance($c));
    }

    public function test_a_brought_forward_balance_can_be_forgiven_too(): void
    {
        $c = $this->customer(['opening_balance' => '800.00']);
        app(PartyAdjustmentService::class)->writeOff('customer', $c->id, '300.00', 'writeoff', 'old debt');
        $docs = app(PartyLedgerService::class)->openDocuments('customer', $c->id);
        $this->assertSame([['party_ledger_entries', 50000]], array_map(fn ($d) => [$d['table'], $d['open']], $docs));
        $this->assertSame('500.00', $this->customerBalance($c));
        $this->receive($c, '500.00');
        $this->assertSame('0.00', $this->customerBalance($c));
        $this->assertSame([], app(PartyLedgerService::class)->openDocuments('customer', $c->id));
    }

    public function test_a_supplier_can_give_us_a_discount_on_what_we_owe(): void
    {
        [$supplier, $grn] = $this->supplierWithReceipt('10', '100');
        app(PartyAdjustmentService::class)->writeOff('supplier', $supplier->id, '150.00', 'discount', 'early payment');
        $this->assertSame('150.00', DB::table('goods_receipts')->where('id', $grn->id)->value('written_off_total'));
        $this->assertSame('850.00', $this->supplierBalance($supplier));
        $this->assertDatabaseHas('party_ledger_entries', ['party_type' => 'supplier', 'party_id' => $supplier->id, 'entry_type' => 'discount', 'debit' => '150.00']);
        $this->fund('cash', '1000.00');
        $this->pay($supplier, '850.00');
        $this->assertSame('0.00', $this->supplierBalance($supplier));
        $this->assertBooksHold();
    }

    public function test_the_ledger_adjustment_moves_a_balance_either_way_for_both_kinds_of_party(): void
    {
        $c = $this->customer(['opening_balance' => '100.00']);
        [$supplier] = $this->supplierWithReceipt('1', '500');
        $service = app(PartyAdjustmentService::class);
        $this->actingAsUser($this->accountant);

        $service->adjust('customer', $c->id, 'increase', '40.00', 'opening balance was keyed wrong');
        $this->assertSame('140.00', $this->customerBalance($c));
        $service->adjust('customer', $c->id, 'decrease', '90.00', 'agreed allowance');
        $this->assertSame('50.00', $this->customerBalance($c));
        $service->adjust('supplier', $supplier->id, 'increase', '25.00', 'bank charge on their side');
        $this->assertSame('525.00', $this->supplierBalance($supplier));
        $service->adjust('supplier', $supplier->id, 'decrease', '125.00', 'credit note');
        $this->assertSame('400.00', $this->supplierBalance($supplier));
        $this->assertDatabaseHas('audit_logs', ['action' => 'party_adjustment', 'reason' => 'credit note']);
        $this->assertSame(4, DB::table('party_ledger_entries')->where('entry_type', 'adjustment')->count());
    }

    public function test_a_locked_period_refuses_write_offs_and_adjustments_dated_inside_it(): void
    {
        $c = $this->customer();
        $this->dueSale($c);
        $yesterday = now('Asia/Dhaka')->subDay()->toDateString();
        app(SettingsService::class)->update(['period_lock_date' => $yesterday]);
        $this->expectError('PERIOD_LOCKED', fn () => app(PartyAdjustmentService::class)->writeOff('customer', $c->id, '10.00', 'writeoff', 'x', $yesterday));
        $this->expectError('PERIOD_LOCKED', fn () => app(PartyAdjustmentService::class)->adjust('customer', $c->id, 'increase', '10.00', 'x', $yesterday));
        $this->expectError('VALIDATION', fn () => app(PartyAdjustmentService::class)->adjust('customer', $c->id, 'increase', '0', 'x'));
    }
}
