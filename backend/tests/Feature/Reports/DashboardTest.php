<?php

namespace Tests\Feature\Reports;

use App\Services\Finance\AccountLedgerService;
use App\Services\Finance\DuesService;
use App\Services\Finance\ExpenseService;
use App\Services\SettingsService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** The dashboard: role-aware widgets, ranges, deltas and the figures that must match the reports and the finance screens. */
class DashboardTest extends ReportsTestCase
{
    /** @return array<string, mixed> */
    private function widgets(string $query = 'range=today'): array
    {
        return $this->getJson('/api/v1/dashboard?'.$query)->assertOk()->json('data.widgets');
    }

    public function test_each_role_gets_only_the_widgets_it_may_see(): void
    {
        $this->books();
        $full = ['kpi-sales', 'kpi-orders', 'kpi-collections', 'sales-trend', 'payment-method-donut', 'top-products', 'recent-sales', 'top-vehicle-models', 'kpi-profit', 'kpi-dues', 'account-balances', 'low-stock'];
        $keys = function (string $role): array {
            $this->actingAsRole($role);
            $k = array_keys($this->widgets());
            sort($k);

            return $k;
        };
        $sorted = fn (array $a) => (function () use ($a) {
            sort($a);

            return $a;
        })();
        $this->assertSame($sorted([...$full, 'pending-approvals']), $keys('super_admin'));
        $this->assertSame($sorted([...$full, 'pending-approvals']), $keys('manager'));
        $this->assertSame($sorted($full), $keys('accountant'), 'the accountant approves nothing, so no pending approvals');
        $this->assertSame($sorted(['kpi-sales', 'kpi-orders', 'kpi-collections', 'sales-trend', 'payment-method-donut', 'top-products', 'recent-sales', 'low-stock']), $keys('cashier'));
        $this->assertSame(['low-stock'], $keys('storekeeper'));
    }

    public function test_a_cashier_sees_only_their_own_sales_and_no_profit(): void
    {
        $this->books();
        $manager = $this->userWithRole('manager');
        $this->shiftFor($manager);
        $this->checkout($this->payload([$this->variant => '1'], ['payments' => [['method' => 'cash', 'amount' => '1150.00']], 'client_expected_grand_total' => '1150.00']), $manager);
        $this->actingAsUser($this->cashier);
        $w = $this->widgets();
        $this->assertSame('5200.00', $w['kpi-sales']['value']);
        $this->assertSame(4, (int) $w['kpi-orders']['value']);
        $this->assertSame(array_unique(array_map(fn ($r) => $this->sales['A']->cashier_id, $w['recent-sales']['rows'])), [$this->cashier->id]);
        $this->assertArrayNotHasKey('kpi-profit', $w);
        $this->assertStringNotContainsString('profit', json_encode($w));
        $this->assertStringNotContainsString('cogs', json_encode($w));
    }

    public function test_ranges_set_the_dates_and_the_trend_has_a_point_for_every_day(): void
    {
        $this->books();
        $this->actingAsRole('manager');
        $d = fn (string $q) => $this->getJson('/api/v1/dashboard?'.$q)->assertOk()->json('data');
        $today = $d('range=today');
        $this->assertSame([$this->today, $this->today], [$today['from'], $today['to']]);
        $this->assertCount(7, $today['widgets']['sales-trend']['points'], 'a single day still draws the last week');
        $this->assertSame($this->today, end($today['widgets']['sales-trend']['points'])['date']);
        $this->assertSame('5200.00', end($today['widgets']['sales-trend']['points'])['total']);
        $this->assertCount(7, $d('range=7d')['widgets']['sales-trend']['points']);
        $this->assertCount(30, $d('range=30d')['widgets']['sales-trend']['points']);
        $this->assertSame(substr($this->today, 0, 8).'01', $d('range=month')['from']);
        $custom = $d('range=custom&from='.now('Asia/Dhaka')->subDays(2)->toDateString().'&to='.$this->today);
        $this->assertCount(3, $custom['widgets']['sales-trend']['points']);
        $this->getJson('/api/v1/dashboard?range=custom&from=2026-02-10&to=2026-02-01')->assertStatus(422);
        $this->getJson('/api/v1/dashboard?range=forever')->assertStatus(422);
    }

    public function test_the_change_against_the_previous_period_is_worked_out_on_the_same_figures(): void
    {
        $this->books();
        DB::table('sales')->where('id', $this->sales['A']->id)->update(['sale_date' => now('Asia/Dhaka')->subDay()->setTime(11, 0)->utc()]);
        $this->actingAsRole('manager');
        $w = $this->widgets();
        $this->assertSame(['3200.00', '2000.00', '1200.00', '60.0'], [$w['kpi-sales']['value'], $w['kpi-sales']['previous'], $w['kpi-sales']['delta']['change'], $w['kpi-sales']['delta']['percent']]);
        $this->assertSame(['3', '1', '2', '200.0'], [(string) $w['kpi-orders']['value'], (string) $w['kpi-orders']['previous'], $w['kpi-orders']['delta']['change'], $w['kpi-orders']['delta']['percent']]);
        $this->actingAsRole('manager');
        $none = $this->getJson('/api/v1/dashboard?range=custom&from=2020-01-01&to=2020-01-01')->json('data.widgets');
        $this->assertNull($none['kpi-sales']['delta']['percent'], 'nothing before means no percent, not a divide by zero');
        $this->assertSame('0.00', $none['kpi-sales']['value']);
    }

    public function test_balances_dues_low_stock_and_approvals_match_their_own_screens(): void
    {
        $this->books();
        DB::table('product_variants')->where('id', $this->variant)->update(['reorder_level' => '10000.000']);
        app(SettingsService::class)->update(['expense_approval_threshold' => 100]);
        $this->actingAsUser($this->accountant);
        app(ExpenseService::class)->create($this->accountant, ['category_id' => DB::table('expense_categories')->value('id'), 'amount' => '500.00', 'account_id' => $this->account('bkash')->id]);
        $this->actingAsRole('manager');
        $w = $this->widgets();

        $bal = app(AccountLedgerService::class)->balances();
        $this->assertSame(Dec::fmt(array_sum($bal), Dec::MONEY), Dec::fmt(array_sum(array_map(fn ($r) => Dec::parse($r['balance'], Dec::MONEY), $w['account-balances']['rows'])), Dec::MONEY));
        $this->assertSame(app(DuesService::class)->list('customer', [])['summary']['total'], $w['kpi-dues']['receivable']);
        $this->assertSame(app(DuesService::class)->list('supplier', [])['summary']['total'], $w['kpi-dues']['payable']);
        $this->assertSame($this->variant, $w['low-stock']['rows'][0]['variant_id']);
        $this->assertGreaterThanOrEqual(1, $w['low-stock']['low']);
        $this->assertSame(['returns' => 0, 'adjustments' => 0, 'expenses' => 1, 'payments' => 0], $w['pending-approvals']);
    }

    public function test_top_products_and_vehicle_models_come_from_the_invoices(): void
    {
        $this->books();
        $this->actingAsRole('manager');
        $w = $this->widgets();
        $this->assertCount(1, $w['top-products']['rows']);
        $this->assertSame('3.000', $w['top-products']['rows'][0]['qty']);
        $this->assertSame([], $w['top-vehicle-models']['rows'], 'no invoice carries a vehicle yet');
        $sale = $this->sales['A'];
        DB::table('sales')->where('id', $sale->id)->update(['vehicle_snapshot' => json_encode(['make' => 'Toyota', 'model' => 'Axio', 'year' => 2015])]);
        $models = $this->widgets()['top-vehicle-models']['rows'];
        $this->assertSame(['Toyota', 'Axio', '1.000'], [$models[0]['make'], $models[0]['model'], $models[0]['qty']]);
    }
}
