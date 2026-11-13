<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FundTransferService;
use App\Services\PermissionService;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Role × endpoint matrix for every Phase 4 route behind a permission (NFR-010). Routes and the permission matrix are read from the
 * application itself: guests get 401, a role holding any of a route's permissions gets anything but 401/403, every other role gets
 * 403 before any data is touched.
 */
class FinanceAuthorizationMatrixTest extends FinanceTestCase
{
    private const PREFIXES = ['financial-accounts.', 'party-payments.', 'party-ledger.', 'day-book.', 'dues.', 'expense-categories.', 'expenses.', 'fund-transfers.', 'settings.period-lock.'];

    /** @var array<string, int> route parameter name → an existing record id */
    private array $ids = [];

    /** @return array<int, LaravelRoute> */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            foreach (self::PREFIXES as $p) {
                if (str_starts_with($name, $p) && collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))) {
                    $out[] = $route;
                    break;
                }
            }
        }

        return $out;
    }

    private function fixtures(): void
    {
        $c = $this->customer();
        $this->dueSale($c);
        $payment = $this->receive($c, '100.00');
        $this->fund('bkash', '1000.00');
        $this->actingAsUser($this->accountant);
        $expense = app(ExpenseService::class)->create($this->accountant, ['category_id' => DB::table('expense_categories')->value('id'), 'amount' => '10.00', 'account_id' => $this->account('bkash')->id]);
        $transfer = app(FundTransferService::class)->create($this->accountant, ['from_account_id' => $this->account('bkash')->id, 'to_account_id' => $this->account('bank')->id, 'amount' => '20.00']);
        $this->ids = [
            'account' => $this->account('bank')->id, 'payment' => $payment->id, 'expense' => $expense->id, 'transfer' => $transfer->id,
            'category' => (int) DB::table('expense_categories')->where('name_en', 'Marketing')->value('id'),
        ];
        $this->assertSame(1, Expense::query()->count());
    }

    private function uri(LaravelRoute $route): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($this->ids[$m[1]] ?? 1), $route->uri());
    }

    public function test_the_matrix_covers_a_real_number_of_routes(): void
    {
        $this->assertGreaterThan(30, count($this->routes()), 'the route scan found too few routes; the matrix would be empty');
    }

    public function test_every_finance_route_sits_behind_authentication_and_a_permission_except_the_two_open_reads(): void
    {
        $open = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            foreach (self::PREFIXES as $p) {
                if (str_starts_with($name, $p) && ! collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))) {
                    $open[] = $name;
                    $this->assertContains('auth:sanctum', $route->gatherMiddleware(), "{$name} must at least need a login");
                }
            }
        }
        sort($open);
        $this->assertSame(['expense-categories.index', 'settings.period-lock.show'], $open);
    }

    public function test_guests_get_401_everywhere(): void
    {
        $this->fixtures();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        foreach ($this->routes() as $route) {
            $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
            $this->json($method, $this->uri($route))->assertStatus(401, "{$method} {$route->uri()} must reject guests");
        }
    }

    public function test_every_role_gets_exactly_the_routes_its_permissions_allow(): void
    {
        $this->fixtures();
        $permissions = app(PermissionService::class);
        $failures = [];
        // Refused roles go first: allowed roles may void or delete the fixtures.
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
                    $status = $this->json($method, $this->uri($route))->getStatusCode();
                    $ok = $allowed ? ! in_array($status, [401, 403, 500], true) : $status === 403;
                    if (! $ok) {
                        $failures[] = sprintf('%s %s %s → %d (%s)', $role, $method, $route->uri(), $status, $allowed ? 'should be allowed' : 'should be forbidden');
                    }
                }
            }
        }
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_the_default_matrix_gives_each_role_the_finance_powers_the_spec_lists(): void
    {
        $p = app(PermissionService::class);
        $can = fn (string $role, string $key) => $p->userHas($this->userWithRole($role), $key);
        $this->assertTrue($can('cashier', 'ledger.receive_payment'));
        foreach (['ledger.view', 'ledger.pay_supplier', 'ledger.writeoff', 'expenses.manage', 'accounts.manage', 'fund_transfers.manage', 'payments.verify', 'periods.lock'] as $key) {
            $this->assertFalse($can('cashier', $key), "cashier must not have {$key}");
            $this->assertFalse($can('storekeeper', $key), "storekeeper must not have {$key}");
        }
        $this->assertTrue($can('accountant', 'ledger.writeoff'));
        $this->assertFalse($can('manager', 'ledger.writeoff'));
        $this->assertFalse($can('manager', 'periods.lock'));
        $this->assertFalse($can('accountant', 'periods.lock'));
        $this->assertTrue($can('super_admin', 'periods.lock'));
    }
}
