<?php

namespace Tests\Feature\Reports;

use App\Models\Sale;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FundTransferService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinanceTestCase;

/**
 * A small set of honest books to report on: cash and bKash sales, a discounted credit sale, a VAT sale, a resellable return, a broken
 * return, a void, a receipt, an expense, a transfer, a supplier receipt and a stock adjustment. Figures are re-derived from the
 * rows with integer arithmetic, independent of the report SQL.
 */
abstract class ReportsTestCase extends FinanceTestCase
{
    /** @var array<string, Sale> */
    protected array $sales = [];

    protected string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = now('Asia/Dhaka')->toDateString();
    }

    protected function books(): void
    {
        $c1 = $this->customer(['name' => 'Report Garage']);
        // A: two mirrors, half cash half bKash.
        $this->sales['A'] = $this->checkout($this->payload([$this->variant => '2'], ['customer_id' => $c1->id, 'payments' => [['method' => 'cash', 'amount' => '1000.00'], ['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678', 'trx_id' => 'REP-1']]]));
        // B: a credit sale with ৳100 off the line.
        $b = $this->payload([$this->variant => '1'], ['customer_id' => $c1->id, 'payments' => [], 'client_expected_grand_total' => '900.00']);
        $b['lines'][0]['discount_type'] = 'amount';
        $b['lines'][0]['discount_value'] = '100.00';
        $b['approvals'] = ['manager_pin' => '2345', 'reason' => 'loyal customer'];
        $this->sales['B'] = $this->checkout($b);
        // C: with 15 % VAT on top.
        DB::table('products')->whereIn('id', DB::table('product_variants')->where('id', $this->variant)->pluck('product_id'))->update(['tax_rate_id' => DB::table('tax_rates')->where('code', 'VAT15')->value('id')]);
        $this->sales['C'] = $this->checkout($this->payload([$this->variant => '1'], ['payments' => [['method' => 'cash', 'amount' => '1150.00']], 'client_expected_grand_total' => '1150.00']));
        // E: a second VAT sale that stays as sold.
        $this->sales['E'] = $this->checkout($this->payload([$this->variant => '1'], ['payments' => [['method' => 'cash', 'amount' => '1150.00']], 'client_expected_grand_total' => '1150.00']));
        // D: sold and voided.
        $this->sales['D'] = $this->checkout($this->payload([$this->variant => '1'], ['payments' => [['method' => 'cash', 'amount' => '1150.00']], 'client_expected_grand_total' => '1150.00']));
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        app(SaleService::class)->void($manager, $this->sales['D'], 'test void');
        // Returns: one of A's two mirrors back on the shelf, and C's mirror back broken.
        app(SaleReturnService::class)->create($manager, $this->sales['A']->refresh(), ['items' => [['sale_item_id' => $this->sales['A']->items()->first()->id, 'qty' => '1', 'condition' => 'resellable']], 'refund_mode' => 'original_method']);
        app(SaleReturnService::class)->create($manager, $this->sales['C']->refresh(), ['items' => [['sale_item_id' => $this->sales['C']->items()->first()->id, 'qty' => '1', 'condition' => 'broken']], 'refund_mode' => 'ledger_credit']);
        // Money moving without a sale.
        $this->receive($c1, '300.00');
        $this->fund('bkash', '5000.00');
        $this->actingAsUser($this->accountant);
        app(ExpenseService::class)->create($this->accountant, ['category_id' => DB::table('expense_categories')->value('id'), 'amount' => '250.00', 'account_id' => $this->account('bkash')->id]);
        app(FundTransferService::class)->create($this->accountant, ['from_account_id' => $this->account('bkash')->id, 'to_account_id' => $this->account('bank')->id, 'amount' => '800.00', 'fee_amount' => '10.00']);
        [$supplier] = $this->supplierWithReceipt('4', '300');
        $this->assertNotNull($supplier->id);
        $this->actingAsRole('super_admin');
    }

    /** Sold invoices (not void, not held) as rows. @return array<int, Sale> */
    protected function soldSales(): array
    {
        return Sale::query()->whereIn('status', ['completed', 'partially_returned', 'returned'])->get()->all();
    }

    /** Revenue (ex-VAT, net of returns) and cost of goods over every sold line, in paisa, worked out here and not in SQL. @return array{revenue: int, cogs: int} */
    protected function lineProfit(): array
    {
        $rev = 0;
        $cogs = 0;
        foreach (DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->whereIn('s.status', ['completed', 'partially_returned', 'returned'])->get(['i.*']) as $i) {
            $qty = Dec::parse((string) $i->qty, Dec::QTY);
            $net = $qty - Dec::parse((string) $i->qty_returned, Dec::QTY);
            $ex = Dec::parse((string) $i->line_total, Dec::MONEY) - Dec::parse((string) $i->tax_amount, Dec::MONEY);
            $rev += intdiv(2 * $ex * $net + $qty, 2 * $qty);
            $cogs += intdiv(2 * Dec::parse((string) $i->unit_cost, Dec::COST) * $net + 100000, 200000);
        }

        return ['revenue' => $rev, 'cogs' => $cogs];
    }

    /** @return array<string, mixed> the "data" of a report response */
    protected function report(string $id, array $query = []): array
    {
        return $this->getJson('/api/v1/reports/'.$id.'?'.http_build_query($query + ['from' => $this->today, 'to' => $this->today, 'per_page' => 200]))->assertOk()->json();
    }

    /** A posted breakage adjustment of two mirrors, so the stock and breakage reports have something to show. */
    protected function adjustStock(): void
    {
        $this->actingAsRole('super_admin');
        $adj = $this->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $this->showroom, 'reason_id' => $this->reasonId('breakage'), 'items' => [['variant_id' => $this->variant, 'qty_delta' => '-2', 'is_breakage' => true]]])->assertCreated()->json('data');
        $this->postJson("/api/v1/stock-adjustments/{$adj['id']}/post")->assertOk();
    }
}
