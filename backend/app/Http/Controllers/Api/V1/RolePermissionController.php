<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateRolePermissionsRequest;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** FR-102 Role × permission matrix editable by roles.manage (super_admin by default). */
class RolePermissionController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'roles' => Permissions::ROLES,
                'keys' => Permissions::KEYS,
                'matrix' => $this->permissions->matrix(),
                'defaults' => array_combine(Permissions::ROLES, array_map(fn ($r) => Permissions::defaultsFor($r), Permissions::ROLES)),
            ],
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, string $role): JsonResponse
    {
        /** @var array<string, bool> $changes */
        $changes = $request->validated()['permissions'];
        DB::transaction(function () use ($role, $changes): void {
            foreach ($changes as $key => $allowed) {
                $row = RolePermission::query()->firstOrNew(['role' => $role, 'permission_key' => $key]);
                $row->allowed = (bool) $allowed;
                if ($row->isDirty()) {
                    $row->save(); // Auditable logs permission_change with before/after
                }
            }
        });
        $this->permissions->forget($role);

        return $this->index();
    }

    public function reset(string $role): JsonResponse
    {
        abort_unless(in_array($role, Permissions::ROLES, true) && $role !== 'super_admin', 404);
        DB::transaction(function () use ($role): void {
            foreach (Permissions::defaultsFor($role) as $key => $allowed) {
                $row = RolePermission::query()->firstOrNew(['role' => $role, 'permission_key' => $key]);
                $row->allowed = $allowed;
                if ($row->isDirty()) {
                    $row->save();
                }
            }
        });
        $this->permissions->forget($role);

        return $this->index();
    }
}
