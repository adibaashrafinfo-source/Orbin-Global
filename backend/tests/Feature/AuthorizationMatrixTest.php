<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Notification;
use App\Models\NumberSequence;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Warehouse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Role × endpoint matrix (NFR-010, AT-019): every P0 endpoint is hit as a guest and as each of the five roles.
 * Allowed roles get a non-403 answer; everyone else gets 403; guests get 401.
 */
class AuthorizationMatrixTest extends TestCase
{
    /** @return array<string, array{string, string, array<int, string>, array<string, mixed>}> */
    public static function endpoints(): array
    {
        $all = self::ROLES;
        $admin = ['super_admin'];
        $adminManager = ['super_admin', 'manager'];

        return [
            'GET settings/company' => ['GET', 'settings/company', $all, []],
            'PUT settings/company' => ['PUT', 'settings/company', $admin, ['legal_name' => 'X', 'address_line1' => 'Y', 'phones' => ['01700000000']]],
            'GET settings/app' => ['GET', 'settings/app', $all, []],
            'PUT settings/app' => ['PUT', 'settings/app', $admin, ['cashier_max_discount_percent' => 7]],
            'GET settings/numbering' => ['GET', 'settings/numbering', $admin, []],
            'PUT settings/numbering/{id}' => ['PUT', 'settings/numbering/{sequence}', $admin, ['prefix' => 'ORB-INV', 'date_token' => 'YYMM', 'padding' => 6, 'reset_policy' => 'yearly']],
            'GET users' => ['GET', 'users', $admin, []],
            'POST users' => ['POST', 'users', $admin, ['full_name' => 'T', 'email' => 't@orbin.local', 'role' => 'cashier', 'password' => 'Password123']],
            'PUT users/{id}' => ['PUT', 'users/{user}', $admin, ['full_name' => 'Renamed']],
            'POST users/{id}/reset-password' => ['POST', 'users/{user}/reset-password', $admin, ['password' => 'Password123']],
            'POST users/{id}/pin' => ['POST', 'users/{user}/pin', $admin, ['pin' => '4321']],
            'GET users/{other}' => ['GET', 'users/{user}', $admin, []],
            'GET role-permissions' => ['GET', 'role-permissions', $all, []],
            'PUT role-permissions/{role}' => ['PUT', 'role-permissions/cashier', $admin, ['permissions' => ['pos.sell' => true]]],
            'POST role-permissions/{role}/reset' => ['POST', 'role-permissions/cashier/reset', $admin, []],
            'GET branches' => ['GET', 'branches', $all, []],
            'POST branches' => ['POST', 'branches', $admin, ['code' => 'B2', 'name' => 'Branch 2']],
            'PUT branches/{id}' => ['PUT', 'branches/{branch}', $admin, ['name' => 'Renamed']],
            'GET warehouses' => ['GET', 'warehouses', $all, []],
            'POST warehouses' => ['POST', 'warehouses', $admin, ['branch_id' => '{branch_id}', 'code' => 'W2', 'name' => 'W2']],
            'PUT warehouses/{id}' => ['PUT', 'warehouses/{warehouse}', $admin, ['name' => 'Renamed']],
            'GET storage-locations' => ['GET', 'storage-locations', $all, []],
            'POST storage-locations' => ['POST', 'storage-locations', ['super_admin', 'manager', 'storekeeper'], ['warehouse_id' => '{warehouse_id}', 'code' => 'R9', 'kind' => 'rack']],
            'PUT storage-locations/{id}' => ['PUT', 'storage-locations/{storage_location}', ['super_admin', 'manager', 'storekeeper'], ['name' => 'Renamed']],
            'GET audit-logs' => ['GET', 'audit-logs', ['super_admin', 'manager'], []],
            'GET audit-logs/export' => ['GET', 'audit-logs/export', $adminManager, []],
            'GET notifications' => ['GET', 'notifications', $all, []],
            'PUT notifications/read-all' => ['PUT', 'notifications/read-all', $all, []],
            'GET admin/health' => ['GET', 'admin/health', $admin, []],
            'POST admin/health/clear-caches' => ['POST', 'admin/health/clear-caches', $admin, []],
            'POST admin/health/run-migrations' => ['POST', 'admin/health/run-migrations', $admin, []],
            'POST auth/pin/verify' => ['POST', 'auth/pin/verify', $all, ['pin' => '1234']],
            'PUT auth/profile' => ['PUT', 'auth/profile', $all, ['full_name' => 'Me']],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_endpoint_is_gated_by_permission(string $method, string $uri, array $allowedRoles, array $payload): void
    {
        $other = User::factory()->role('cashier')->create(['email' => 'other@orbin.local']);
        $branch = Branch::query()->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();
        $location = StorageLocation::withoutEvents(fn () => StorageLocation::query()->create(['warehouse_id' => $warehouse->id, 'code' => 'A1', 'kind' => 'rack']));
        $sequence = NumberSequence::query()->where('key', 'invoice')->firstOrFail();

        $resolve = fn (string $s): string => strtr($s, [
            '{user}' => (string) $other->id, '{branch}' => (string) $branch->id, '{warehouse}' => (string) $warehouse->id,
            '{storage_location}' => (string) $location->id, '{sequence}' => (string) $sequence->id,
        ]);
        $payload = array_map(fn ($v) => is_string($v) ? strtr($v, ['{branch_id}' => (string) $branch->id, '{warehouse_id}' => (string) $warehouse->id]) : $v, $payload);
        $url = '/api/v1/'.$resolve($uri);

        // Guest → 401
        $this->json($method, $url, $payload)->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');

        foreach (self::ROLES as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAsRole($role);
            $status = $this->json($method, $url, $payload)->getStatusCode();
            if (in_array($role, $allowedRoles, true)) {
                $this->assertNotContains($status, [401, 403], "{$role} should reach {$method} {$uri} but got {$status}");
            } else {
                $this->assertSame(403, $status, "{$role} must be forbidden on {$method} {$uri} but got {$status}");
            }
        }
    }

    public function test_user_can_read_own_profile_but_not_others(): void
    {
        $cashier = $this->actingAsRole('cashier');
        $this->getJson('/api/v1/users/'.$cashier->id)->assertOk();
        $manager = $this->userWithRole('manager');
        $this->getJson('/api/v1/users/'.$manager->id)->assertStatus(403);
    }

    public function test_notification_can_only_be_marked_read_by_owner(): void
    {
        $cashier = $this->userWithRole('cashier');
        $manager = $this->userWithRole('manager');
        $n = Notification::query()->create(['user_id' => $cashier->id, 'type' => 'low_stock', 'title' => 'x']);
        $this->actingAsUser($manager);
        $this->putJson('/api/v1/notifications/'.$n->id.'/read')->assertStatus(403);
        $this->actingAsUser($cashier);
        $this->putJson('/api/v1/notifications/'.$n->id.'/read')->assertOk()->assertJsonPath('data.is_read', true);
    }

    public function test_inactive_user_session_is_denied_everywhere(): void
    {
        $user = $this->userWithRole('manager');
        User::withoutEvents(fn () => $user->forceFill(['is_active' => false])->save());
        $this->actingAsUser($user);
        $this->getJson('/api/v1/branches')->assertStatus(403);
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);
    }
}
