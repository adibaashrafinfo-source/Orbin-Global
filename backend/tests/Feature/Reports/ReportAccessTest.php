<?php

namespace Tests\Feature\Reports;

use App\Models\ReportPreset;
use App\Models\Sale;
use App\Reports\ReportRegistry;
use App\Services\PermissionService;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/** Who may open, export and save presets for which reports; cost and profit stay hidden without the cost permission (AT-019). */
class ReportAccessTest extends ReportsTestCase
{
    /** @return array<int, string> */
    private function catalog(string $role): array
    {
        $this->actingAsRole($role);

        return array_column($this->getJson('/api/v1/reports')->assertOk()->json('data'), 'id');
    }

    public function test_each_role_sees_exactly_the_reports_its_permissions_allow(): void
    {
        $all = array_keys(app(ReportRegistry::class)->all());
        $this->assertSame($all, $this->catalog('super_admin'));
        $this->assertSame($all, $this->catalog('manager'));
        $audit = ['audit-price-changes', 'audit-overrides', 'audit-login'];
        $this->assertSame(array_values(array_diff($all, $audit)), $this->catalog('accountant'), 'the accountant has no audit.view');
        $stock = $this->catalog('storekeeper');
        $this->assertCount(10, $stock, 'the eleven stock reports minus valuation, which needs the cost permission');
        $this->assertNotContains('stock-valuation', $stock);
        $this->assertSame(['stock-current'], array_slice($stock, 0, 1));
        $this->assertSame(['sales-summary', 'sales-by-product', 'sales-by-payment-method'], $this->catalog('cashier'));
    }

    public function test_a_report_outside_a_role_is_forbidden_and_an_unknown_one_is_not_found(): void
    {
        $this->actingAsRole('cashier');
        foreach (['sales-by-cashier', 'profit-by-invoice', 'stock-current', 'day-book', 'audit-login', 'receivable-aging'] as $id) {
            $this->getJson("/api/v1/reports/{$id}")->assertForbidden();
        }
        $this->getJson('/api/v1/reports/not-a-report')->assertNotFound();
        $this->actingAsRole('storekeeper');
        foreach (['sales-summary', 'profit-loss', 'day-book', 'purchase-summary'] as $id) {
            $this->getJson("/api/v1/reports/{$id}")->assertForbidden();
        }
        $this->getJson('/api/v1/reports/stock-low-out-over')->assertOk();
    }

    public function test_cost_and_profit_columns_are_left_out_for_people_without_the_cost_permission(): void
    {
        $this->books();
        $this->adjustStock();
        $this->actingAsRole('cashier');
        $rows = $this->report('sales-by-product');
        $this->assertSame(['item', 'sku', 'qty', 'revenue'], array_column($rows['data']['columns'], 'key'));
        $this->assertArrayNotHasKey('cogs', $rows['data']['totals']);
        $this->assertArrayNotHasKey('profit', $rows['data']['totals']);
        $this->assertStringNotContainsString('cogs', json_encode($rows));

        $this->actingAsRole('storekeeper');
        $stock = $this->report('stock-current');
        $keys = array_column($stock['data']['columns'], 'key');
        $this->assertNotContains('avg_cost', $keys);
        $this->assertNotContains('value', $keys);
        $this->getJson('/api/v1/reports/stock-valuation')->assertForbidden();
        $this->assertStringNotContainsString('avg_cost', json_encode($this->report('stock-aging')));

        $this->actingAsRole('manager');
        $this->assertContains('avg_cost', array_column($this->report('stock-current')['data']['columns'], 'key'));
    }

    public function test_a_cashier_reports_only_on_their_own_sales(): void
    {
        $this->books();
        $manager = $this->userWithRole('manager');
        $this->shiftFor($manager);
        $this->checkout($this->payload([$this->variant => '1'], ['payments' => [['method' => 'cash', 'amount' => '1150.00']], 'client_expected_grand_total' => '1150.00']), $manager);
        $this->actingAsUser($this->cashier);
        $own = $this->report('sales-summary')['data']['totals'];
        $this->assertSame('4', $own['invoices'], "the cashier's own four sold invoices, not the manager's");
        $this->assertSame('5200.00', $own['total']);
        $pay = $this->report('sales-by-payment-method')['data'];
        $this->assertNotContains('Later receipts', array_column($pay['rows'], 'source'), 'later receipts are not a cashier-scoped figure');
        $this->actingAsRole('manager');
        $this->assertSame('5', $this->report('sales-summary')['data']['totals']['invoices']);
    }

    public function test_exporting_needs_the_export_permission(): void
    {
        $this->actingAsRole('cashier');
        $this->get('/api/v1/reports/sales-summary/export?format=csv')->assertForbidden();
        $this->actingAsRole('storekeeper');
        $this->get('/api/v1/reports/stock-current/export?format=csv')->assertForbidden();
        $this->actingAsRole('manager');
        $this->get('/api/v1/reports/sales-summary/export?format=csv')->assertOk();
        $this->get('/api/v1/reports/sales-summary/export?format=docx')->assertStatus(422);
        $this->get('/api/v1/reports/sales-summary/export')->assertStatus(422);
        $this->actingAsRole('accountant');
        $this->get('/api/v1/reports/audit-login/export?format=csv')->assertForbidden();
    }

    public function test_input_is_validated(): void
    {
        $this->actingAsRole('super_admin');
        $this->getJson('/api/v1/reports/sales-summary?from=yesterday')->assertStatus(422)->assertJsonStructure(['fields' => ['from']]);
        $this->getJson('/api/v1/reports/sales-summary?per_page=500')->assertStatus(422);
        $this->getJson('/api/v1/reports/sales-summary?dir=sideways')->assertStatus(422);
        $this->assertSame('2026-02-01', $this->getJson('/api/v1/reports/sales-summary?from=2026-02-20&to=2026-02-01')->json('meta.filters.from'), 'a reversed range is put the right way round');
        $this->assertSame(substr($this->today, 0, 8).'01', $this->getJson('/api/v1/reports/sales-summary')->json('meta.filters.from'), 'the default range is this month so far');
    }

    public function test_the_screen_pages_and_sorts_on_the_server_and_totals_cover_every_row(): void
    {
        $this->books();
        $page = $this->getJson('/api/v1/reports/sales-by-product?group=variant&per_page=1&from='.$this->today.'&to='.$this->today)->assertOk();
        $this->assertSame(1, $page->json('meta.per_page'));
        $this->assertSame('3.000', $page->json('data.totals.qty'));
        $sorted = $this->getJson('/api/v1/reports/profit-by-invoice?sort=profit&dir=desc&from='.$this->today.'&to='.$this->today)->json('data.rows');
        $profits = array_map(fn ($r) => (float) $r['profit'], $sorted);
        $desc = $profits;
        rsort($desc);
        $this->assertSame($desc, $profits);
        $this->assertSame(array_column($this->getJson('/api/v1/reports/sales-summary?group=month&from='.$this->today.'&to='.$this->today)->json('data.rows'), 'period'), [substr($this->today, 0, 7)]);
    }

    public function test_drill_down_links_name_the_document(): void
    {
        $this->books();
        $row = $this->report('profit-by-invoice')['data']['rows'][0];
        $this->assertSame('sales', $row['_links']['invoice']['table']);
        $this->assertSame($row['invoice'], Sale::query()->findOrFail($row['_links']['invoice']['id'])->invoice_no);
        $ret = $this->report('returns-report')['data']['rows'][0];
        $this->assertSame(['sale_returns', 'sales'], [$ret['_links']['return_no']['table'], $ret['_links']['invoice']['table']]);
    }

    public function test_presets_are_private_replaceable_and_checked(): void
    {
        $this->actingAsRole('manager');
        $body = ['report_id' => 'sales-summary', 'name' => 'This week', 'filters' => ['range' => 'week', 'compare' => true, 'group' => 'day']];
        $id = $this->postJson('/api/v1/report-presets', $body)->assertCreated()->assertJsonPath('data.filters.range', 'week')->json('data.id');
        $again = $this->postJson('/api/v1/report-presets', ['filters' => ['range' => 'month']] + $body)->assertCreated();
        $this->assertSame($id, $again->json('data.id'), 'saving under the same name replaces it');
        $this->assertSame(1, ReportPreset::query()->count());
        $this->assertSame('month', $this->getJson('/api/v1/report-presets?report_id=sales-summary')->json('data.0.filters.range'));

        $this->postJson('/api/v1/report-presets', ['filters' => ['range' => 'decade']] + $body)->assertStatus(422);
        $this->postJson('/api/v1/report-presets', ['report_id' => 'nope'] + $body)->assertNotFound();
        $this->postJson('/api/v1/report-presets', ['name' => ''] + $body)->assertStatus(422);

        $this->actingAsRole('accountant');
        $this->getJson('/api/v1/report-presets')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/report-presets/{$id}")->assertForbidden();
        $this->actingAsRole('storekeeper');
        $this->postJson('/api/v1/report-presets', $body)->assertForbidden();
        $this->actingAsRole('manager');
        $this->deleteJson("/api/v1/report-presets/{$id}")->assertNoContent();
        $this->assertSame(0, ReportPreset::query()->count());
    }

    /** @return array<int, LaravelRoute> */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if ((str_starts_with($name, 'reports.') || str_starts_with($name, 'report-presets.')) && $name !== 'report-presets.destroy' && collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))) {
                $out[] = $route;
            }
        }

        return $out;
    }

    public function test_the_role_by_endpoint_matrix_for_reports(): void
    {
        $this->assertCount(5, $this->routes());
        $permissions = app(PermissionService::class);
        $failures = [];
        foreach ([false, true] as $wantAllowed) {
            foreach (self::ROLES as $role) {
                $user = $this->actingAsRole($role);
                foreach ($this->routes() as $route) {
                    $needed = explode('|', substr((string) collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')), 11));
                    $allowed = $permissions->userHasAny($user, $needed);
                    if ($allowed !== $wantAllowed) {
                        continue;
                    }
                    $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
                    $uri = '/'.preg_replace('/\{report\}/', 'sales-summary', $route->uri());
                    $status = $this->json($method, $uri.($route->getName() === 'reports.export' ? '?format=csv' : ''))->getStatusCode();
                    // The route lets anyone with some report permission in; the report itself then checks its own (a storekeeper may not open sales).
                    $reportOpen = ! str_contains($route->uri(), '{report}') || app(ReportRegistry::class)->canOpen($user, app(ReportRegistry::class)->find('sales-summary'));
                    $ok = $allowed && $reportOpen ? ! in_array($status, [401, 403, 500], true) : $status === 403;
                    if (! $ok) {
                        $failures[] = sprintf('%s %s %s → %d', $role, $method, $route->uri(), $status);
                    }
                }
            }
        }
        $this->assertSame([], $failures, implode("\n", $failures));
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/reports')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
