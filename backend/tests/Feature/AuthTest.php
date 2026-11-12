<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_every_seeded_role_can_log_in_and_receives_its_permissions(): void
    {
        foreach (self::ROLES as $role) {
            $user = $this->userWithRole($role);
            $res = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => DemoUserSeeder::PASSWORD]);
            $res->assertOk()
                ->assertJsonPath('data.user.email', $user->email)
                ->assertJsonPath('data.user.role', $role)
                ->assertJsonMissingPath('data.user.password_hash');
            $perms = $res->json('data.permissions');
            if ($role === 'super_admin') {
                $this->assertSame(['*'], $perms);
            } else {
                $this->assertNotContains('*', $perms);
                $this->assertNotEmpty($perms);
            }
            $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'entity_table' => 'users', 'entity_id' => $user->id]);
            $this->postJson('/api/v1/auth/logout')->assertOk();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $user = $this->userWithRole('cashier');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope-nope'])
            ->assertStatus(422)->assertJsonPath('code', 'AUTH_INVALID_CREDENTIALS');
        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed', 'entity_id' => $user->id]);
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->userWithRole('storekeeper');
        User::withoutEvents(fn () => $user->forceFill(['is_active' => false])->save());
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => DemoUserSeeder::PASSWORD])
            ->assertStatus(403)->assertJsonPath('code', 'AUTH_INACTIVE');
    }

    public function test_login_locks_after_five_failed_attempts(): void
    {
        $user = $this->userWithRole('cashier');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => DemoUserSeeder::PASSWORD])
            ->assertStatus(429)->assertJsonPath('code', 'AUTH_THROTTLED');
    }

    public function test_login_route_has_a_per_ip_throttle_that_is_configurable(): void
    {
        config(['orbin.login_route_per_minute' => 3]);
        $user = $this->userWithRole('cashier');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "nobody{$i}@orbin.local", 'password' => 'x'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => DemoUserSeeder::PASSWORD])
            ->assertStatus(429)->assertJsonPath('code', 'AUTH_THROTTLED');
    }

    public function test_me_returns_session_and_settings(): void
    {
        $this->actingAsRole('manager');
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.role', 'manager')
            ->assertJsonPath('data.settings.idle_lock_minutes', 10)
            ->assertJsonStructure(['data' => ['user', 'permissions', 'settings']]);
    }

    public function test_pin_verify_succeeds_fails_and_locks(): void
    {
        $this->actingAsRole('manager'); // seeded PIN 2345
        $this->postJson('/api/v1/auth/pin/verify', ['pin' => '2345'])->assertOk()->assertJsonPath('data.verified', true);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/pin/verify', ['pin' => '0000'])->assertStatus(422)->assertJsonPath('code', 'PIN_INVALID');
        }
        $this->postJson('/api/v1/auth/pin/verify', ['pin' => '0000'])->assertStatus(423)->assertJsonPath('code', 'PIN_LOCKED');
        $this->postJson('/api/v1/auth/pin/verify', ['pin' => '2345'])->assertStatus(423)->assertJsonPath('code', 'PIN_LOCKED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'pin_failed']);
    }

    public function test_pin_verify_without_pin_set(): void
    {
        $this->actingAsRole('cashier');
        $this->postJson('/api/v1/auth/pin/verify', ['pin' => '1234'])->assertStatus(422)->assertJsonPath('code', 'PIN_NOT_SET');
    }

    public function test_profile_and_password_change(): void
    {
        $user = $this->actingAsRole('cashier');
        $this->putJson('/api/v1/auth/profile', ['full_name' => 'New Name', 'language' => 'bn', 'phone' => '01711111111'])
            ->assertOk()->assertJsonPath('data.user.full_name', 'New Name')->assertJsonPath('data.user.language', 'bn');
        $this->putJson('/api/v1/auth/profile', ['phone' => '12345'])->assertStatus(422)->assertJsonPath('code', 'VALIDATION');

        $this->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertStatus(422);
        $this->putJson('/api/v1/auth/password', ['current_password' => DemoUserSeeder::PASSWORD, 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertOk();
        $this->assertTrue(Hash::check('NewPass123', $user->refresh()->password_hash));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_change', 'entity_id' => $user->id]);
        // Hashes never land in the audit log.
        $this->assertSame(0, AuditLog::query()->where('after', 'like', '%$2y$%')->count());
    }

    public function test_forgot_password_sends_reset_and_reset_updates_password(): void
    {
        Notification::fake();
        $user = $this->userWithRole('accountant');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('data.sent', true);
        Notification::assertSentTo($user, ResetPassword::class);
        // Unknown email gets the same answer (no enumeration).
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@orbin.local'])->assertOk();

        $token = Password::broker()->createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'Reset12345', 'password_confirmation' => 'Reset12345',
        ])->assertOk();
        $this->assertTrue(Hash::check('Reset12345', $user->refresh()->password_hash));
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'bad', 'email' => $user->email, 'password' => 'Reset12345', 'password_confirmation' => 'Reset12345',
        ])->assertStatus(422)->assertJsonPath('code', 'AUTH_RESET_INVALID');
    }
}
