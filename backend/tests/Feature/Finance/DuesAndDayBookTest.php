<?php

namespace Tests\Feature\Finance;

use App\Models\ExpenseCategory;
use App\Services\Finance\AccountLedgerService;
use App\Services\Finance\AccountService;
use App\Services\Finance\DayBookService;
use App\Services\Finance\DuesService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FundTransferService;
use App\Services\Finance\PartyAdjustmentService;
use App\Services\Finance\PartyLedgerService;
use App\Services\Finance\PartyPaymentService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-1006, FR-1007 and the gate invariants: dues add up to the ledger, and the account balances equal the day book. */
class DuesAndDayBookTest extends FinanceTestCase
{
    private function ageSale(int $saleId, int $days): void
    {
        DB::table('sales')->where('id', $saleId)->update(['sale_date' => now()->subDays($days)]);
    }

    /** @return array<string, array<string, mixed>> dues rows keyed by party name */
    private function dues(string $type, array $filters = []): array
    {
        $out = app(DuesService::class)->list($type, $filters + ['per_page' => 100]);

        return collect($out['data'])->keyBy('name')->all();
    }

    public function test_receivables_are_bucketed_by_invoice_age_and_add_up_to_the_ledger(): void
    {
        $c = $this->customer(['name' => 'Aging Garage']);
        $this->ageSale($this->dueSale($c, 1)->id, 5);
        $this->ageSale($this->dueSale($c, 2)->id, 20);
        $this->ageSale($this->dueSale($c, 3)->id, 100);

        $row = $this->dues('customer')['Aging Garage'];
        $this->assertSame('6000.00', $row['balance']);
        $this->assertSame(['d0_15' => '1000.00', 'd16_30' => '2000.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'd90_plus' => '3000.00'], $row['buckets']);
        $this->assertSame(3, $row['open_documents']);

        // 3,500 clears the 100-day invoice and 500 of the 20-day one: 1,500 left at 20 days, 1,000 at 5 days.
        $this->receive($c, '3500.00');
        $row = $this->dues('customer')['Aging Garage'];
        $this->assertSame(['d0_15' => '1000.00', 'd16_30' => '1500.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'd90_plus' => '0.00'], $row['buckets']);
        $this->assertSame('2500.00', $row['balance']);
    }

    public function test_an_advance_settles_the_oldest_dues_so_only_the_newest_stay_in_the_buckets(): void
    {
        $c = $this->customer(['name' => 'Advance Garage']);
        $old = $this->dueSale($c);
        $this->ageSale($old->id, 40);
        // The customer hands over 1,500 for a 1,000 invoice: 500 is advance. A new 800 invoice then leaves 300 owing.
        $this->receive($c, '1500.00');
        $this->dueSale($c, 1);
        DB::table('sales')->where('customer_id', $c->id)->orderByDesc('id')->limit(1)->update(['grand_total' => '800.00', 'due_total' => '800.00']);
        DB::table('party_ledger_entries')->where(['party_id' => $c->id, 'entry_type' => 'invoice'])->orderByDesc('id')->limit(1)->update(['debit' => '800.00']);

        $row = $this->dues('customer')['Advance Garage'];
        $this->assertSame('300.00', $row['balance']);
        $this->assertSame('300.00', $row['buckets']['d0_15']);
        $this->assertSame('0.00', $row['buckets']['d31_60']);
    }

    public function test_a_balance_no_document_explains_counts_as_current(): void
    {
        $c = $this->customer(['name' => 'Adjusted Garage']);
        $this->actingAsUser($this->accountant);
        app(PartyAdjustmentService::class)->adjust('customer', $c->id, 'increase', '200.00', 'keyed wrong');
        $row = $this->dues('customer')['Adjusted Garage'];
        $this->assertSame(['200.00', '200.00'], [$row['balance'], $row['buckets']['d0_15']]);
    }

    public function test_payables_are_bucketed_by_receipt_date(): void
    {
        [$supplier] = $this->supplierWithReceipt('10', '100', now('Asia/Dhaka')->subDays(30)->toDateString());
        $this->actingAsRole('super_admin');
        $grn = app(GoodsReceiptService::class);
        $today = $grn->post($grn->create(['warehouse_id' => $this->showroom, 'supplier_id' => $supplier->id, 'received_date' => now('Asia/Dhaka')->toDateString(), 'allocation_basis' => 'value',
            'items' => [['variant_id' => $this->variant, 'qty_received' => '5', 'qty_damaged' => '0', 'unit_cost' => '100']], 'extra_costs' => []]));
        $this->assertSame('1500.00', $this->supplierBalance($supplier));
        $this->fund('cash', '1000.00');
        $this->pay($supplier, '700.00');

        $row = collect(app(DuesService::class)->list('supplier', ['per_page' => 100])['data'])->firstWhere('id', $supplier->id);
        // 700 clears most of the 30-day receipt: 300 left at 30 days, 500 at today.
        $this->assertSame(['800.00', '500.00', '300.00'], [$row['balance'], $row['buckets']['d0_15'], $row['buckets']['d16_30']]);
        $this->assertNotNull($today->id);
    }

    public function test_the_summary_equals_the_sum_of_every_balance_and_the_filters_work(): void
    {
        $a = $this->customer(['name' => 'Alpha Motors']);
        $b = $this->customer(['name' => 'Beta Garage']);
        $paid = $this->customer(['name' => 'Gamma Paid']);
        $this->ageSale($this->dueSale($a, 2)->id, 70);
        $this->dueSale($b, 1);
        $this->dueSale($paid, 1);
        $this->receive($paid, '1000.00');

        $out = app(DuesService::class)->list('customer', ['per_page' => 100]);
        $this->assertSame(['Alpha Motors', 'Beta Garage'], array_column($out['data'], 'name'), 'sorted by balance, the settled customer is not listed');
        $this->assertSame(['3000.00', 2], [$out['summary']['total'], $out['summary']['parties']]);
        $this->assertSame('2000.00', $out['summary']['buckets']['d61_90']);
        $this->assertSame('3000.00', Dec::fmt(array_sum(array_map(fn ($v) => Dec::parse($v, 2), $out['summary']['buckets'])), 2));

        $this->assertSame(['Alpha Motors'], array_column(app(DuesService::class)->list('customer', ['bucket' => 'd61_90'])['data'], 'name'));
        $this->assertSame(['Beta Garage'], array_column(app(DuesService::class)->list('customer', ['search' => 'beta'])['data'], 'name'));
        $this->assertSame(['Alpha Motors', 'Beta Garage'], array_column(app(DuesService::class)->list('customer', ['sort' => 'oldest'])['data'], 'name'));
        $page2 = app(DuesService::class)->list('customer', ['per_page' => 1, 'page' => 2]);
        $this->assertSame(['Beta Garage', 2, 2], [$page2['data'][0]['name'], $page2['meta']['total'], $page2['meta']['last_page']]);
    }

    public function test_a_promise_to_pay_is_kept_with_the_party_and_can_be_cleared(): void
    {
        $c = $this->customer(['name' => 'Promise Garage']);
        $this->dueSale($c);
        [$supplier] = $this->supplierWithReceipt();
        $when = now('Asia/Dhaka')->addDays(3)->toDateString();
        $dues = app(DuesService::class);
        $this->actingAsUser($this->accountant);

        $dues->setPromise('customer', $c->id, $when, 'will pay on Friday');
        $dues->setPromise('supplier', $supplier->id, $when, 'after the shipment clears');
        $this->assertSame([$when, 'will pay on Friday'], [$this->dues('customer')['Promise Garage']['promise_date'], $this->dues('customer')['Promise Garage']['promise_note']]);
        $this->assertSame($when, collect($dues->list('supplier', [])['data'])->firstWhere('id', $supplier->id)['promise_date']);
        $dues->setPromise('customer', $c->id, null, 'ignored without a date');
        $this->assertSame([null, null], [$this->dues('customer')['Promise Garage']['promise_date'], $this->dues('customer')['Promise Garage']['promise_note']]);
    }

    /** Move this account's entries on a given day so the day book spans several dates. */
    private function backdate(string $refType, int $days): void
    {
        DB::table('account_transactions')->where('ref_type', $refType)->update(['txn_date' => now('Asia/Dhaka')->subDays($days)->toDateString()]);
    }

    public function test_the_gate_account_balances_equal_the_day_book(): void
    {
        // A busy mix across three accounts: till sales, a credit sale paid later, a supplier bill, an expense, a transfer, a void and an adjustment.
        $c = $this->customer(['name' => 'Ledger Garage']);
        $this->checkout($this->payload([$this->variant => '2'], ['payments' => [['method' => 'bkash', 'amount' => '1500.00', 'sender_number' => '01712345678', 'trx_id' => 'DB-1'], ['method' => 'cash', 'amount' => '500.00']]]));
        $this->backdate('sale_payment', 6);
        $this->dueSale($c, 3, '1000.00');
        $this->receive($c, '1200.00');
        $this->backdate('party_payment', 3);
        [$supplier] = $this->supplierWithReceipt('4', '300');
        $this->fund('cash', '0.00');
        $this->actingAsUser($this->accountant);
        app(DuesService::class)->setPromise('supplier', $supplier->id, null, null);
        $this->pay($supplier, '600.00');
        app(ExpenseService::class)->create($this->accountant, ['category_id' => ExpenseCategory::query()->value('id'), 'amount' => '250.00', 'account_id' => $this->account('cash')->id, 'expense_date' => now('Asia/Dhaka')->subDays(2)->toDateString()]);
        app(FundTransferService::class)->create($this->accountant, ['from_account_id' => $this->account('bkash')->id, 'to_account_id' => $this->account('bank')->id, 'amount' => '800.00', 'fee_amount' => '10.00']);
        $void = $this->receive($c, '100.00');
        app(PartyPaymentService::class)->void($this->accountant, $void, 'keyed twice');
        app(AccountService::class)->adjust($this->account('bank'), 'in', '55.00', 'missed deposit');

        $ledger = app(AccountLedgerService::class);
        $from = now('Asia/Dhaka')->subDays(30)->toDateString();
        $to = now('Asia/Dhaka')->toDateString();
        $book = app(DayBookService::class)->run(['from' => $from, 'to' => $to, 'per_page' => 200]);
        $balances = $ledger->balances();

        foreach ($book['accounts'] as $row) {
            $independent = DB::selectOne("SELECT a.opening_balance + COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END), 0) AS b FROM financial_accounts a LEFT JOIN account_transactions t ON t.account_id = a.id WHERE a.id = ? GROUP BY a.id, a.opening_balance", [$row['account_id']]);
            $this->assertSame(Dec::fmt($balances[$row['account_id']], 2), $row['closing'], $row['name'].' day book closing equals the account balance');
            $this->assertSame(Dec::fmt(Dec::parse((string) $independent->b, 2), 2), $row['closing'], $row['name'].' equals opening + in − out');
            $this->assertSame(Dec::fmt(Dec::parse($row['opening'], 2) + Dec::parse($row['in'], 2) - Dec::parse($row['out'], 2), 2), $row['closing']);
        }
        $this->assertSame(Dec::fmt(array_sum($balances), 2), $book['totals']['closing']);
        $this->assertSame($book['meta']['total'], DB::table('account_transactions')->count());
        $this->assertSame($book['totals']['in'], Dec::fmt(array_sum(array_map(fn ($r) => $r['direction'] === 'in' ? Dec::parse($r['amount'], 2) : 0, $book['rows'])), 2), 'the rows add up to the totals');
        $this->assertSame($book['totals']['out'], Dec::fmt(array_sum(array_map(fn ($r) => $r['direction'] === 'out' ? Dec::parse($r['amount'], 2) : 0, $book['rows'])), 2));

        // Day to day: each day's closing is the next day's opening, per account.
        $previous = null;
        for ($d = 8; $d >= 0; $d--) {
            $day = now('Asia/Dhaka')->subDays($d)->toDateString();
            $one = collect(app(DayBookService::class)->run(['from' => $day, 'to' => $day])['accounts'])->keyBy('account_id');
            if ($previous !== null) {
                foreach ($one as $id => $row) {
                    $this->assertSame($previous[$id]['closing'], $row['opening'], "account {$id} on {$day}");
                }
            }
            $previous = $one;
        }

        // The account's own ledger ends on the same balance.
        foreach ($balances as $id => $balance) {
            $rows = $ledger->transactions($id, null, null, 1, 100)['rows'];
            $this->assertSame(Dec::fmt($balance, 2), $rows[0]['balance'] ?? Dec::fmt($balance, 2), "account {$id} ledger shows the balance after its last entry");
        }

        // And the party books: a statement closes on the balance, however it is cut.
        foreach ([[$c->id, 'customer'], [$supplier->id, 'supplier']] as [$id, $type]) {
            $all = app(PartyLedgerService::class)->statement($type, $id, null, null);
            $this->assertSame(app(PartyLedgerService::class)->balance($type, $id), $all['closing']);
            $cut = app(PartyLedgerService::class)->statement($type, $id, now('Asia/Dhaka')->subDays(2)->toDateString(), null);
            $this->assertSame($all['closing'], $cut['closing'], 'a statement cut part way still closes on the same balance');
        }
        $this->assertBooksHold();
    }

    public function test_the_day_book_can_be_narrowed_to_one_account(): void
    {
        $this->checkout($this->payload([$this->variant => '1'], ['payments' => [['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678', 'trx_id' => 'ONE-1']]]));
        $today = now('Asia/Dhaka')->toDateString();
        $book = app(DayBookService::class)->run(['from' => $today, 'to' => $today, 'account_id' => $this->account('bkash')->id]);
        $this->assertCount(1, $book['accounts']);
        $this->assertSame(['1000.00', '1000.00'], [$book['totals']['in'], $book['totals']['closing']]);
        $this->assertCount(1, $book['rows']);
        $this->assertSame(['sale_payment', 'in'], [$book['rows'][0]['ref_type'], $book['rows'][0]['direction']]);
    }
}
