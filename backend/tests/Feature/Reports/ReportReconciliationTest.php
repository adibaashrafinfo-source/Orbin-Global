<?php

namespace Tests\Feature\Reports;

use App\Services\Finance\AccountLedgerService;
use App\Services\Finance\DayBookService;
use App\Services\Finance\DuesService;
use App\Services\Inventory\StockQueryService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** The Phase 5 gate (AT-017): dashboard = report = invoices, and profit = Σ(line price − line cost). Every figure is re-derived from the rows. */
class ReportReconciliationTest extends ReportsTestCase
{
    public function test_the_gate_dashboard_sales_equal_the_report_equal_the_sum_of_the_invoices(): void
    {
        $this->books();
        $sum = Dec::fmt(array_sum(array_map(fn ($s) => Dec::parse((string) $s->grand_total, Dec::MONEY), $this->soldSales())), Dec::MONEY);
        $this->assertSame('5200.00', $sum, 'A 2,000 + B 900 + C 1,150 + E 1,150; the void is not counted');

        $report = $this->report('sales-summary');
        $this->assertSame($sum, $report['data']['totals']['total']);
        $this->assertSame('4', $report['data']['totals']['invoices']);

        $dash = $this->getJson('/api/v1/dashboard?range=today')->assertOk()->json('data.widgets');
        $this->assertSame($sum, $dash['kpi-sales']['value']);
        $this->assertSame(4, (int) $dash['kpi-orders']['value']);

        // The invoice list the cashier and the manager use gives the same total for the same day.
        $list = $this->getJson("/api/v1/sales?filter[from]={$this->today}&filter[to]={$this->today}&filter[status]=&per_page=100")->assertOk();
        $listed = array_sum(array_map(fn ($r) => Dec::parse($r['grand_total'], Dec::MONEY), array_filter($list->json('data'), fn ($r) => $r['status'] !== 'void')));
        $this->assertSame($sum, Dec::fmt($listed, Dec::MONEY));
    }

    public function test_the_gate_profit_equals_the_sum_of_line_price_minus_line_cost_everywhere(): void
    {
        $this->books();
        $expected = $this->lineProfit();
        $profit = Dec::fmt($expected['revenue'] - $expected['cogs'], Dec::MONEY);
        // A: 2 × 1,000 with one back resellable → 1 × (1,000 − 600). B: 900 − 600. C: 1,000 ex-VAT, returned broken → nothing. E: 1,000 − 600.
        $this->assertSame('2900.00', Dec::fmt($expected['revenue'], Dec::MONEY));
        $this->assertSame('1800.00', Dec::fmt($expected['cogs'], Dec::MONEY));
        $this->assertSame('1100.00', $profit);

        $byInvoice = $this->report('profit-by-invoice')['data']['totals'];
        $this->assertSame([Dec::fmt($expected['revenue'], Dec::MONEY), Dec::fmt($expected['cogs'], Dec::MONEY), $profit], [$byInvoice['revenue'], $byInvoice['cogs'], $byInvoice['profit']]);
        $this->assertSame($profit, $this->report('profit-by-product')['data']['totals']['profit']);
        $this->assertSame($profit, $this->report('profit-by-product', ['group' => 'category'])['data']['totals']['profit']);
        $this->assertSame($profit, $this->report('sales-by-product')['data']['totals']['profit']);
        $this->assertSame($profit, $this->report('sales-by-product', ['group' => 'variant'])['data']['totals']['profit']);
        $this->assertSame($profit, $this->report('sales-by-brand')['data']['totals']['profit']);
        $this->assertSame($profit, $this->report('profit-loss')['data']['totals']['gross_profit']);
        $this->assertSame($profit, $this->getJson('/api/v1/dashboard?range=today')->json('data.widgets.kpi-profit.value'));
        $this->assertSame($profit, Dec::fmt(array_sum(array_map(fn ($r) => Dec::parse($r['profit'], Dec::MONEY), $this->report('profit-by-invoice')['data']['rows'])), Dec::MONEY));
    }

    public function test_the_loss_on_a_broken_return_and_the_expenses_reach_net_profit(): void
    {
        $this->books();
        $pl = $this->report('profit-loss')['data']['totals'];
        // The broken mirror came back and was written off at its cost of 600; the expense 250 and the transfer charge 10 are expenses.
        $this->assertSame('600.00', $pl['breakage']);
        $this->assertSame('260.00', $pl['expenses']);
        $this->assertSame('240.00', $pl['net_profit'], '1,100 gross − 600 breakage − 260 expenses');
    }

    public function test_item_figures_are_net_of_returns_and_without_vat_and_a_void_never_counts(): void
    {
        $this->books();
        $this->assertSame('3.000', $this->report('sales-by-product')['data']['totals']['qty'], 'sold 5, 2 came back, the void is not counted');
        $vat = collect($this->report('vat-summary')['data']['rows'])->keyBy('rate');
        $this->assertSame(['1000.00', '150.00'], [$vat['15.00']['taxable'], $vat['15.00']['vat']], 'only E still stands; C was returned in full');
        $this->assertSame('1900.00', $vat['0.00']['taxable']);
        $this->assertNotContains($this->sales['D']->invoice_no, array_column($this->report('profit-by-invoice')['data']['rows'], 'invoice'));
        $this->assertSame([$this->sales['D']->invoice_no], array_column($this->report('void-report')['data']['rows'], 'invoice'));
    }

    public function test_collections_equal_what_was_taken_at_the_till_plus_later_receipts(): void
    {
        $this->books();
        $till = DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->whereIn('s.status', ['completed', 'partially_returned', 'returned'])->sum('p.amount');
        $later = DB::table('party_payments')->where('status', 'posted')->sum('amount');
        $expected = Dec::fmt(Dec::parse((string) $till, Dec::MONEY) + Dec::parse((string) $later, Dec::MONEY), Dec::MONEY);
        $this->assertSame($expected, $this->report('sales-by-payment-method')['data']['totals']['amount']);
        $this->assertSame($expected, $this->getJson('/api/v1/dashboard?range=today')->json('data.widgets.kpi-collections.value'));
        $slices = $this->getJson('/api/v1/dashboard?range=today')->json('data.widgets.payment-method-donut.slices');
        $this->assertSame($expected, Dec::fmt(array_sum(array_map(fn ($s) => Dec::parse($s['amount'], Dec::MONEY), $slices)), Dec::MONEY));
    }

    public function test_finance_reports_equal_the_finance_screens(): void
    {
        $this->books();
        $this->assertSame(app(DuesService::class)->list('customer', [])['summary']['total'], $this->report('receivable-aging')['data']['totals']['balance']);
        $this->assertSame(app(DuesService::class)->list('supplier', [])['summary']['total'], $this->report('payable-aging')['data']['totals']['balance']);

        $book = app(DayBookService::class)->run(['from' => $this->today, 'to' => $this->today]);
        $this->assertSame($book['totals']['in'], $this->report('day-book')['data']['totals']['in']);
        $this->assertSame($book['totals']['out'], $this->report('day-book')['data']['totals']['out']);
        $bal = $this->report('account-balances')['data']['totals'];
        $this->assertSame([$book['totals']['in'], $book['totals']['out'], $book['totals']['closing']], [$bal['in'], $bal['out'], $bal['closing']]);
        $this->assertSame(Dec::fmt(array_sum(app(AccountLedgerService::class)->balances()), Dec::MONEY), $bal['closing']);

        $this->assertSame('260.00', $this->report('expense-report')['data']['totals']['amount']);
        $this->assertSame(['800.00', '10.00'], [$this->report('fund-transfer-report')['data']['totals']['amount'], $this->report('fund-transfer-report')['data']['totals']['fee']]);
    }

    public function test_stock_reports_equal_the_stock_screens(): void
    {
        $this->books();
        $this->adjustStock();
        $valuation = app(StockQueryService::class)->valuation($this->today, null, null);
        $this->assertSame($valuation['total'], $this->report('stock-valuation', ['as_of' => $this->today])['data']['totals']['value']);
        $this->assertSame($valuation['total'], $this->report('stock-current')['data']['totals']['value'], 'rebuilt from movements = the stock level rows');
        $lost = 0;
        foreach (DB::table('stock_movements')->whereIn('movement_type', ['breakage', 'damage'])->get() as $m) {
            $lost += intdiv(2 * -Dec::parse((string) $m->qty_delta, Dec::QTY) * Dec::parse((string) $m->unit_cost, Dec::COST) + 100000, 200000);
        }
        $this->assertSame(Dec::fmt($lost, Dec::MONEY), $this->report('breakage-report')['data']['totals']['cost']);
        $moves = collect($this->report('stock-movement-summary')['data']['rows'])->keyBy('type');
        $this->assertSame('6.000', $moves['sale']['out']);
        $this->assertSame('3.000', $moves['sale return']['in'], 'the void, the resellable return and the broken return all came back first');
        $this->assertSame(1, count($this->report('adjustment-report')['data']['rows']));
    }

    public function test_a_day_ends_at_midnight_in_dhaka_not_in_utc(): void
    {
        $this->books();
        // 23:30 in Dhaka is 17:30 UTC the same day; 00:30 Dhaka the next day is 18:30 UTC.
        $late = now('Asia/Dhaka')->startOfDay()->setTime(23, 30)->utc();
        $early = now('Asia/Dhaka')->startOfDay()->addDay()->setTime(0, 30)->utc();
        DB::table('sales')->where('id', $this->sales['A']->id)->update(['sale_date' => $late]);
        DB::table('sales')->where('id', $this->sales['B']->id)->update(['sale_date' => $early]);
        $day = $this->report('sales-summary')['data'];
        $this->assertSame(3, (int) $day['totals']['invoices'], 'A (23:30 today), C and E count today; B (00:30 tomorrow) does not');
        $tomorrow = now('Asia/Dhaka')->addDay()->toDateString();
        $next = $this->getJson("/api/v1/reports/sales-summary?from={$tomorrow}&to={$tomorrow}")->assertOk()->json('data.totals');
        $this->assertSame(['1', '900.00'], [$next['invoices'], $next['total']]);
    }

    public function test_compare_to_the_previous_period_uses_the_same_length_just_before(): void
    {
        $this->books();
        DB::table('sales')->where('id', $this->sales['A']->id)->update(['sale_date' => now('Asia/Dhaka')->subDay()->setTime(12, 0)->utc()]);
        $r = $this->report('sales-summary', ['compare' => 1])['data'];
        $yesterday = now('Asia/Dhaka')->subDay()->toDateString();
        $this->assertSame([$yesterday, $yesterday, '2000.00'], [$r['compare']['from'], $r['compare']['to'], $r['compare']['totals']['total']]);
        $this->assertSame('3200.00', $r['totals']['total']);
        $this->assertSame(['1200.00', '60.0'], [$r['compare']['delta']['total']['change'], $r['compare']['delta']['total']['percent']]);
        $week = $this->report('sales-summary', ['from' => now('Asia/Dhaka')->subDays(6)->toDateString(), 'to' => $this->today, 'compare' => 1])['data']['compare'];
        $this->assertSame(now('Asia/Dhaka')->subDays(13)->toDateString(), $week['from']);
    }
}
