<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Seed the starter data (branches, settings, permission matrix, sequences, lookups, demo users). */
    protected bool $seed = true;

    public const ROLES = ['super_admin', 'manager', 'cashier', 'storekeeper', 'accountant'];

    protected function setUp(): void
    {
        parent::setUp();
        // The SPA always sends an Origin header; Sanctum uses it to decide the request is stateful (cookie session).
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    protected function userWithRole(string $role): User
    {
        $email = collect(DemoUserSeeder::USERS)->firstWhere('role', $role)['email'];

        return User::query()->where('email', $email)->firstOrFail();
    }

    protected function actingAsRole(string $role): User
    {
        return $this->actingAsUser($this->userWithRole($role));
    }

    /** Switch identity mid-test: drops guards and the session password hash used by AuthenticateSession. */
    protected function actingAsUser(User $user): User
    {
        $this->app['auth']->forgetGuards();
        if ($this->app->bound('session.store')) {
            $this->app['session.store']->flush();
        }
        $this->actingAs($user);

        return $user;
    }
}
