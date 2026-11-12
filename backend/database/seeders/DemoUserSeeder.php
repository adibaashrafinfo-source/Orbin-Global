<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One login per role for local, testing and staging. Never seeded in production.
 * Password for all: Orbin@2026 — change on staging after the first login.
 */
class DemoUserSeeder extends Seeder
{
    public const PASSWORD = 'Orbin@2026';

    public const USERS = [
        ['email' => 'admin@orbin.local', 'full_name' => 'Owner Admin', 'role' => 'super_admin', 'pin' => '1234'],
        ['email' => 'manager@orbin.local', 'full_name' => 'Shop Manager', 'role' => 'manager', 'pin' => '2345'],
        ['email' => 'cashier@orbin.local', 'full_name' => 'Counter Cashier', 'role' => 'cashier', 'pin' => null],
        ['email' => 'store@orbin.local', 'full_name' => 'Store Keeper', 'role' => 'storekeeper', 'pin' => null],
        ['email' => 'accounts@orbin.local', 'full_name' => 'Accountant', 'role' => 'accountant', 'pin' => null],
    ];

    public function run(): void
    {
        $branch = Branch::query()->where('code', 'HQ')->first();
        User::withoutEvents(function () use ($branch): void {
            foreach (self::USERS as $row) {
                User::query()->firstOrCreate(
                    ['email' => $row['email']],
                    [
                        'full_name' => $row['full_name'],
                        'role' => $row['role'],
                        'password_hash' => Hash::make(self::PASSWORD),
                        'override_pin_hash' => $row['pin'] !== null ? Hash::make($row['pin']) : null,
                        'default_branch_id' => $branch?->id,
                        'branch_ids' => $branch ? [$branch->id] : null,
                        'language' => 'en',
                        'is_active' => true,
                        'must_change_password' => false,
                    ],
                );
            }
        });
    }
}
