<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersTest extends TestCase
{
    public function test_admin_lists_filters_and_creates_users(): void
    {
        $this->actingAsRole('super_admin');
        $this->getJson('/api/v1/users')->assertOk()->assertJsonPath('meta.total', 5);
        $this->getJson('/api/v1/users?filter[role]=cashier')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/users?search=Store')->assertOk()->assertJsonPath('data.0.role', 'storekeeper');

        $res = $this->postJson('/api/v1/users', [
            'full_name' => 'New Cashier', 'email' => 'New.Cashier@Orbin.local', 'phone' => '01722222222', 'role' => 'cashier',
            'password' => 'Password123', 'default_branch_id' => 1, 'branch_ids' => [1],
        ]);
        $res->assertCreated()->assertJsonPath('data.email', 'new.cashier@orbin.local')->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password_hash');
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'entity_table' => 'users', 'entity_id' => $res->json('data.id')]);
        $this->assertSame(0, AuditLog::query()->where('after', 'like', '%Password123%')->count());

        $this->postJson('/api/v1/users', ['full_name' => 'Dup', 'email' => 'cashier@orbin.local', 'role' => 'cashier', 'password' => 'Password123'])
            ->assertStatus(422)->assertJsonStructure(['fields' => ['email']]);
        $this->postJson('/api/v1/users', ['full_name' => 'Weak', 'email' => 'w@orbin.local', 'role' => 'cashier', 'password' => 'short'])
            ->assertStatus(422)->assertJsonStructure(['fields' => ['password']]);
    }

    public function test_update_reset_password_pin_and_deactivate(): void
    {
        $admin = $this->actingAsRole('super_admin');
        $cashier = $this->userWithRole('cashier');

        $this->putJson('/api/v1/users/'.$cashier->id, ['full_name' => 'Renamed', 'role' => 'storekeeper'])
            ->assertOk()->assertJsonPath('data.full_name', 'Renamed')->assertJsonPath('data.role', 'storekeeper');
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'entity_table' => 'users', 'entity_id' => $cashier->id]);

        $this->postJson('/api/v1/users/'.$cashier->id.'/reset-password', ['password' => 'Changed123'])->assertOk();
        $this->assertTrue(Hash::check('Changed123', $cashier->refresh()->password_hash));
        $this->assertTrue($cashier->must_change_password);

        $this->postJson('/api/v1/users/'.$cashier->id.'/pin', ['pin' => '9876'])->assertOk()->assertJsonPath('data.has_override_pin', true);
        $this->postJson('/api/v1/users/'.$cashier->id.'/pin', ['pin' => '12'])->assertStatus(422);
        $this->postJson('/api/v1/users/'.$cashier->id.'/pin', ['pin' => null])->assertOk()->assertJsonPath('data.has_override_pin', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pin_change', 'entity_id' => $cashier->id]);

        $this->putJson('/api/v1/users/'.$cashier->id, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->putJson('/api/v1/users/'.$admin->id, ['is_active' => false])->assertStatus(422)->assertJsonPath('code', 'SELF_DEACTIVATE');
    }

    public function test_last_active_super_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = $this->actingAsRole('super_admin');
        $second = User::factory()->role('super_admin')->create();
        $this->actingAsUser($second);
        $this->putJson('/api/v1/users/'.$admin->id, ['role' => 'manager'])->assertOk();
        $this->putJson('/api/v1/users/'.$second->id, ['role' => 'manager'])->assertStatus(422)->assertJsonPath('code', 'LAST_SUPER_ADMIN');
    }

    public function test_only_super_admin_may_manage_super_admins(): void
    {
        $manager = $this->userWithRole('manager');
        // Grant users.manage to manager through the matrix, then verify role escalation is still blocked.
        $this->actingAsRole('super_admin');
        $this->putJson('/api/v1/role-permissions/manager', ['permissions' => ['users.manage' => true]])->assertOk();
        $this->actingAsUser($manager);
        $this->postJson('/api/v1/users', ['full_name' => 'Boss', 'email' => 'boss@orbin.local', 'role' => 'super_admin', 'password' => 'Password123'])
            ->assertStatus(403);
        $this->postJson('/api/v1/users', ['full_name' => 'Clerk', 'email' => 'clerk@orbin.local', 'role' => 'cashier', 'password' => 'Password123'])
            ->assertCreated();
        $admin = $this->userWithRole('super_admin');
        $this->putJson('/api/v1/users/'.$admin->id, ['full_name' => 'Hijack'])->assertStatus(403);
    }
}
