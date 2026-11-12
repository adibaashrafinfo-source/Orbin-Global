<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    public function test_matrix_is_readable_and_complete(): void
    {
        $this->actingAsRole('cashier');
        $res = $this->getJson('/api/v1/role-permissions')->assertOk();
        $this->assertSame(Permissions::ROLES, $res->json('data.roles'));
        $this->assertSame(Permissions::KEYS, $res->json('data.keys'));
        $matrix = $res->json('data.matrix');
        $this->assertTrue($matrix['super_admin']['users.manage']);
        $this->assertFalse($matrix['cashier']['products.view_cost']);
        $this->assertTrue($matrix['cashier']['pos.sell']);
    }

    public function test_changing_a_permission_takes_effect_immediately_and_is_audited(): void
    {
        $this->actingAsRole('storekeeper');
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->actingAsRole('super_admin');
        $res = $this->putJson('/api/v1/role-permissions/storekeeper', ['permissions' => ['audit.view' => true, 'pos.sell' => false]])->assertOk();
        $this->assertTrue($res->json('data.matrix')['storekeeper']['audit.view']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'permission_change', 'entity_table' => 'role_permissions']);

        $this->app['auth']->forgetGuards();
        $this->actingAsRole('storekeeper');
        $this->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_super_admin_row_and_unknown_keys_are_rejected(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson('/api/v1/role-permissions/super_admin', ['permissions' => ['pos.sell' => false]])->assertStatus(422);
        $this->putJson('/api/v1/role-permissions/cashier', ['permissions' => ['made.up' => true]])->assertStatus(422);
        $this->putJson('/api/v1/role-permissions/nope', ['permissions' => ['pos.sell' => true]])->assertStatus(422);
    }

    public function test_reset_restores_spec_defaults(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson('/api/v1/role-permissions/cashier', ['permissions' => ['pos.sell' => false, 'products.view_cost' => true]])->assertOk();
        $res = $this->postJson('/api/v1/role-permissions/cashier/reset')->assertOk();
        $this->assertSame(Permissions::defaultsFor('cashier'), $res->json('data.matrix.cashier'));
    }
}
