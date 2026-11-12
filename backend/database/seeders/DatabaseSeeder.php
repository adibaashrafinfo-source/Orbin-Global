<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Idempotent starter data (spec seedData). Safe to run repeatedly; never overwrites admin edits.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            WarehouseSeeder::class,
            CompanyProfileSeeder::class,
            AppSettingsSeeder::class,
            RolePermissionSeeder::class,
            NumberSequenceSeeder::class,
            LookupSeeder::class,
        ]);

        // Demo logins (one per role) only when explicitly enabled; production creates its admin via orbin:make-super-admin.
        if (filter_var(env('SEED_DEMO_USERS', false), FILTER_VALIDATE_BOOL) && ! app()->isProduction()) {
            $this->call(DemoUserSeeder::class);
        }
    }
}
