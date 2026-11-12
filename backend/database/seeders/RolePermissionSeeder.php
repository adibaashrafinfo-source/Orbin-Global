<?php

namespace Database\Seeders;

use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/** Full role × key matrix from spec roles.defaultMatrix. Existing rows (admin edits) are kept. */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        RolePermission::withoutEvents(function (): void {
            foreach (Permissions::ROLES as $role) {
                foreach (Permissions::defaultsFor($role) as $key => $allowed) {
                    RolePermission::query()->firstOrCreate(
                        ['role' => $role, 'permission_key' => $key],
                        ['allowed' => $allowed],
                    );
                }
            }
        });
        $service = app(PermissionService::class);
        foreach (Permissions::ROLES as $role) {
            $service->forget($role);
        }
    }
}
