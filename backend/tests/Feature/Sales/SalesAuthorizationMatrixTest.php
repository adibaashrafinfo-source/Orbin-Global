<?php

namespace Tests\Feature\Sales;

use App\Models\SalePayment;
use App\Services\PermissionService;
use App\Services\Sales\CustomerService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Services\Sales\WarrantyService;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Role × endpoint matrix for every Phase 3 route behind a permission (NFR-010, AT-019). Routes and the permission matrix are read
 * from the application itself: guests get 401, a role holding any of a route's permissions gets anything but 401/403, and every
 * other role gets 403 before any data is touched.
 */
class SalesAuthorizationMatrixTest extends SalesTestCase
{
    private const PREFIXES = ['customers.', 'customer-vehicles.', 'pos.', 'sales.', 'sale-returns.', 'sale-payments.', 'quotations.', 'shifts.', 'warranty-claims.'];

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
        [$v] = $this->stocked(1, '50');
        $customer = $this->customer();
        $vehicle = app(CustomerService::class)->saveVehicle($customer, ['reg_no' => 'MATRIX-1']);
        $sale = $this->checkout($this->payload([$v => '2'], ['customer_id' => $customer->id, 'payments' => [['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678', 'trx_id' => 'MX1']]]));
        $held = app(SaleService::class)->hold($this->cashier, ['payload' => ['lines' => []]]);
        $this->actingAsUser($this->cashier);
        $return = app(SaleReturnService::class)->create($this->cashier, $sale->refresh(), ['items' => [['sale_item_id' => $sale->items()->first()->id, 'qty' => '1', 'condition' => 'resellable']], 'refund_mode' => 'ledger_credit']);
        $quote = app(QuotationService::class)->create($this->cashier, ['lines' => [['variant_id' => $v, 'qty' => '1']]]);
        $claim = app(WarrantyService::class)->create($this->cashier, ['sale_item_id' => $sale->items()->first()->id, 'issue' => 'matrix fixture']);
        $this->ids = [
            'customer' => $customer->id, 'vehicle' => $vehicle->id, 'sale' => $sale->id, 'saleReturn' => $return->id, 'quotation' => $quote->id, 'shift' => $this->shift->id,
            'payment' => SalePayment::query()->where('sale_id', $sale->id)->value('id'), 'warrantyClaim' => $claim->id,
        ];
        $this->assertNotNull($held->id);
    }

    private function uri(LaravelRoute $route): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($this->ids[$m[1]] ?? 1), $route->uri());
    }

    public function test_the_matrix_covers_a_real_number_of_routes(): void
    {
        $this->assertGreaterThan(40, count($this->routes()), 'the route scan found too few routes; the matrix would be empty');
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
        // Refused roles go first: allowed roles may void, delete or approve the fixtures.
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

    public function test_a_cashier_sees_only_their_own_invoices_and_a_manager_sees_all(): void
    {
        [$v] = $this->stocked(1, '20');
        $mine = $this->checkout($this->payload([$v => '1']));
        $manager = $this->userWithRole('manager');
        $this->shiftFor($manager);
        $theirs = $this->checkout($this->payload([$v => '1']), $manager);

        $this->actingAsUser($this->cashier);
        $this->getJson("/api/v1/sales/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/sales/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/v1/sales/{$theirs->id}/pdf")->assertForbidden();
        $this->getJson("/api/v1/sales/{$theirs->id}/receipt")->assertForbidden();
        $this->assertSame([$mine->id], collect($this->getJson('/api/v1/sales')->assertOk()->json('data'))->pluck('id')->all());

        $this->actingAsUser($manager);
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], collect($this->getJson('/api/v1/sales')->json('data'))->pluck('id')->all());
    }

    public function test_the_shift_of_another_cashier_is_only_visible_to_those_who_may_view_all(): void
    {
        $manager = $this->userWithRole('manager');
        $other = $this->shiftFor($manager);
        $this->actingAsUser($this->cashier);
        $this->getJson("/api/v1/shifts/{$other->id}")->assertForbidden();
        $this->postJson("/api/v1/shifts/{$other->id}/close", ['denomination_counts' => ['1000' => 0]])->assertForbidden();
        $this->actingAsUser($manager);
        $this->getJson("/api/v1/shifts/{$this->shift->id}")->assertOk();
        $this->postJson("/api/v1/shifts/{$this->shift->id}/close", ['denomination_counts' => ['1000' => 1]])->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_only_a_manager_may_resolve_a_warranty_claim_and_a_cashier_may_raise_one(): void
    {
        [$v] = $this->stocked(1, '5');
        $sale = $this->checkout($this->payload([$v => '1']));
        $this->actingAsUser($this->cashier);
        $id = $this->postJson('/api/v1/warranty-claims', ['sale_item_id' => $sale->items()->first()->id, 'issue' => 'cracked glass'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/warranty-claims/{$id}", ['status' => 'rejected'])->assertForbidden();
        $this->actingAsRole('manager');
        $this->putJson("/api/v1/warranty-claims/{$id}", ['status' => 'in_review'])->assertOk()->assertJsonPath('data.status', 'in_review');
    }
}
