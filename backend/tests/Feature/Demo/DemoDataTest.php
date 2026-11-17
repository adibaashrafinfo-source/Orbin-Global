<?php

namespace Tests\Feature\Demo;

use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Reports\ReportsTestCase;

/**
 * Runs the demo seed as an owner would (orbin:demo-seed) and checks that the sample business works end to end: stock, customers,
 * sales, the dashboard and reports agreeing with the invoices, accounts, the integrity check, and that a second run adds nothing.
 */
class DemoDataTest extends ReportsTestCase
{
    private function demoCompany(): void
    {
        CompanyProfile::current()->forceFill([
            'legal_name' => 'Orbin Global', 'address_line1' => '12 Mirror Lane', 'city' => 'Dhaka', 'phones' => ['01711000000'],
            'email' => 'hello@orbin.test', 'invoice_terms' => 'Goods once sold are returnable within the window.',
        ])->save();
    }

    public function test_the_demo_seed_refuses_without_a_company_profile(): void
    {
        CompanyProfile::current()->forceFill(['legal_name' => '', 'address_line1' => '', 'phones' => []])->save();
        $this->assertFalse(CompanyProfile::current()->invoiceReady());
        $this->artisan('orbin:demo-seed')->assertFailed();
        $this->assertSame(0, Product::query()->where('code', 'like', 'DEMO-%')->count());
    }

    public function test_the_demo_adds_products_stock_customers_and_sales_and_a_second_run_adds_nothing(): void
    {
        $this->demoCompany();
        $this->artisan('orbin:demo-seed')->assertSuccessful();

        $this->assertSame(3, Product::query()->where('code', 'like', 'DEMO-%')->count());
        $this->assertSame(4, Sale::query()->count(), 'four sales: cash, part credit, bKash, and the walk-in');
        $this->assertSame(3, Customer::query()->where('is_walk_in', false)->count());
        $this->assertSame(1, Customer::query()->where('name', 'Demo Garage Rahim')->first()?->vehicles()->count());
        $this->assertSame(['2000.00', '1450.00', '3000.00', '2100.00'], Sale::query()->orderBy('id')->pluck('grand_total')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());

        $this->artisan('orbin:demo-seed')->assertSuccessful();
        $this->assertSame(4, Sale::query()->count(), 'a second run must not double the sales');
        $this->assertSame(3, Product::query()->where('code', 'like', 'DEMO-%')->count());
    }

    public function test_the_demo_books_reconcile_across_dashboard_reports_invoices_and_accounts(): void
    {
        $this->demoCompany();
        $this->artisan('orbin:demo-seed')->assertSuccessful();
        $manager = User::query()->where('role', 'manager')->first() ?? $this->userWithRole('manager');
        $this->actingAsUser($manager);

        $invoiceTotal = (float) Sale::query()->sum('grand_total');
        $paid = (float) Sale::query()->sum('paid_total');
        $cogs = 12 * 600.0; // twelve units sold, each at the 600 opening cost

        $dash = $this->getJson('/api/v1/dashboard?range=today')->assertOk()->json('data.widgets.kpi-sales.value');
        $this->assertEqualsWithDelta($invoiceTotal, (float) $dash, 0.005, 'dashboard sales = invoices');

        $sum = $this->getJson('/api/v1/reports/sales-summary?range=today')->assertOk()->json('data.totals.total');
        $this->assertEqualsWithDelta($invoiceTotal, (float) $sum, 0.005, 'sales summary = invoices');

        $pl = $this->getJson('/api/v1/reports/profit-loss?range=today')->assertOk()->json('data.totals');
        $this->assertEqualsWithDelta($cogs, (float) $pl['cogs'], 0.005, 'cost of goods = 12 units at 600');
        $this->assertEqualsWithDelta($invoiceTotal - $cogs, (float) $pl['gross_profit'], 0.005, 'gross profit = sales - cost');

        $accounts = collect($this->getJson('/api/v1/financial-accounts')->assertOk()->json('data'));
        $this->assertEqualsWithDelta(4600.0 + 1000.0, (float) $accounts->firstWhere('account_type', 'cash')['balance'], 0.005, 'cash = float 1000 + cash sales 4600');
        $this->assertEqualsWithDelta(3000.0, (float) $accounts->firstWhere('account_type', 'bkash')['balance'], 0.005, 'bKash = the bKash sale');
        $this->assertEqualsWithDelta($paid, 4600.0 + 3000.0, 0.005, 'paid = cash + bKash received from sales');

        $this->assertSame(0, Artisan::call('integrity:check'), 'integrity:check exits 0 on the demo books');
    }

    public function test_a_demo_customer_on_credit_shows_the_right_due(): void
    {
        $this->demoCompany();
        $this->artisan('orbin:demo-seed')->assertSuccessful();
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $karim = Customer::query()->where('name', 'Demo Motors Karim')->firstOrFail();
        $this->assertSame('950.00', number_format((float) Sale::query()->where('customer_id', $karim->id)->sum('due_total'), 2, '.', ''));
        $dues = $this->getJson('/api/v1/dues?type=customer')->assertOk()->json('data');
        $row = collect($dues)->firstWhere('id', $karim->id);
        $this->assertNotNull($row, 'the customer appears in dues');
        $this->assertEqualsWithDelta(950.0, (float) $row['balance'], 0.005);
    }
}
