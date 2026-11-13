<?php

namespace Tests\Feature\Inventory;

use App\Models\Attachment;
use App\Services\PermissionService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\SupplierService;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Role × endpoint matrix for every Phase 2 route that sits behind a permission (NFR-010, AT-019). The routes and the
 * permission matrix are read from the application itself, so a route added later cannot be forgotten: guests get 401,
 * a role holding any of the route's permissions gets anything but 401/403, every other role gets 403 before any data is touched.
 */
class InventoryAuthorizationMatrixTest extends InventoryTestCase
{
    private const PREFIXES = ['suppliers.', 'purchase-orders.', 'goods-receipts.', 'purchase-returns.', 'inventory.', 'stock-adjustments.', 'stock-transfers.', 'stock-counts.', 'variants.purchase-history', 'admin.integrity.'];

    /** Routes with a second gate inside the controller, on top of the route permission (value is cost, so it needs the cost permission). */
    private const ALSO_NEEDS = ['inventory.valuation' => 'products.view_cost'];

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
        [$a] = $this->variants();
        $this->stock($a, '50', '100');
        $this->actingAsRole('super_admin');
        $supplier = app(SupplierService::class)->create(['name' => 'Matrix Supplier']);
        $po = app(PurchaseOrderService::class)->create(['supplier_id' => $supplier->id, 'order_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $a, 'qty_ordered' => '5', 'unit_cost' => '10']]]);
        $grn = app(GoodsReceiptService::class)->create(['warehouse_id' => $this->showroom, 'supplier_id' => $supplier->id, 'received_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $a, 'qty_received' => '1', 'unit_cost' => '10']]]);
        $return = app(PurchaseReturnService::class)->create(['supplier_id' => $supplier->id, 'warehouse_id' => $this->showroom, 'return_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $a, 'qty' => '1', 'unit_cost' => '10']]]);
        $adj = $this->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $this->showroom, 'reason_id' => $this->reasonId('found'), 'items' => [['variant_id' => $a, 'qty_delta' => '1']]])->json('data.id');
        $transfer = $this->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $this->showroom, 'to_warehouse_id' => $this->godown, 'items' => [['variant_id' => $a, 'qty_sent' => '1']]])->json('data.id');
        $count = $this->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->showroom])->json('data.id');
        $att = Attachment::query()->create(['entity_table' => 'stock_adjustments', 'entity_id' => $adj, 'storage_bucket' => 'local', 'storage_path' => 'adjustments/none.png', 'file_name' => 'x.png', 'mime_type' => 'image/png']);
        $this->ids = ['supplier' => $supplier->id, 'purchaseOrder' => $po->id, 'goodsReceipt' => $grn->id, 'purchaseReturn' => $return->id, 'stockAdjustment' => $adj, 'stockTransfer' => $transfer, 'stockCount' => $count, 'variant' => $a, 'attachment' => $att->id];
    }

    private function uri(LaravelRoute $route): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($this->ids[$m[1]] ?? 1), $route->uri());
    }

    public function test_the_matrix_covers_a_real_number_of_routes(): void
    {
        $this->assertGreaterThan(60, count($this->routes()), 'the route scan found too few routes; the matrix would be empty');
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
        // Roles that must be refused go first for every route: allowed roles below may delete or change the fixtures.
        foreach ([false, true] as $wantAllowed) {
            foreach (self::ROLES as $role) {
                $user = $this->actingAsRole($role);
                foreach ($this->routes() as $route) {
                    $needed = explode('|', substr((string) collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')), 11));
                    $allowed = $permissions->userHasAny($user, $needed)
                        && (! isset(self::ALSO_NEEDS[(string) $route->getName()]) || $permissions->userHas($user, self::ALSO_NEEDS[(string) $route->getName()]));
                    if ($allowed !== $wantAllowed) {
                        continue;
                    }
                    $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
                    $status = $this->json($method, $this->uri($route))->getStatusCode();
                    $ok = $allowed ? ! in_array($status, [401, 403], true) : $status === 403;
                    if (! $ok) {
                        $failures[] = sprintf('%s %s %s → %d (%s)', $role, $method, $route->uri(), $status, $allowed ? 'should be allowed' : 'should be forbidden');
                    }
                }
            }
        }
        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
