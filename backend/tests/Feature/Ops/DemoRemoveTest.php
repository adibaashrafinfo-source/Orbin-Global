<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Tests\TestCase;

class DemoRemoveTest extends TestCase
{
    public function test_it_soft_deletes_only_the_demo_logins(): void
    {
        $this->seed(DemoUserSeeder::class);
        $real = User::factory()->create(['email' => 'owner@shop.example']);

        $this->artisan('orbin:demo-remove', ['--force' => true])->assertSuccessful();

        foreach (DemoUserSeeder::USERS as $row) {
            $this->assertSoftDeleted('users', ['email' => $row['email']]);
        }
        $this->assertNotSoftDeleted('users', ['id' => $real->id]);
    }

    public function test_it_is_refused_in_production(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('orbin:demo-remove', ['--force' => true])->assertFailed();
        $this->assertDatabaseHas('users', ['email' => 'admin@orbin.local', 'deleted_at' => null]);
    }
}
