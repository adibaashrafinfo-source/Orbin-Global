<?php

namespace App\Services;

use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;

/**
 * Reads role_permissions (cached per role, invalidated on change). super_admin always has everything.
 */
class PermissionService
{
    private const CACHE_PREFIX = 'orbin.role_permissions.';

    /** @return array<int, string> allowed keys for a role (["*"] for super_admin). */
    public function keysForRole(string $role): array
    {
        if ($role === 'super_admin') {
            return [Permissions::WILDCARD];
        }

        /** @var array<int, string> $keys */
        $keys = Cache::remember(self::CACHE_PREFIX.$role, 300, function () use ($role): array {
            return RolePermission::query()
                ->where('role', $role)
                ->where('allowed', true)
                ->orderBy('permission_key')
                ->pluck('permission_key')
                ->all();
        });

        return $keys;
    }

    /** @param  array<int, string>  $keys */
    public function userHasAny(User $user, array $keys): bool
    {
        if (! $user->is_active) {
            return false;
        }
        $allowed = $this->keysForRole($user->role);
        if (in_array(Permissions::WILDCARD, $allowed, true)) {
            return true;
        }
        foreach ($keys as $key) {
            if (in_array($key, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    public function userHas(User $user, string $key): bool
    {
        return $this->userHasAny($user, [$key]);
    }

    public function forget(string $role): void
    {
        Cache::forget(self::CACHE_PREFIX.$role);
    }

    /** @return array<string, array<string, bool>> role => key => allowed */
    public function matrix(): array
    {
        $rows = RolePermission::query()->get(['role', 'permission_key', 'allowed']);
        $matrix = [];
        foreach (Permissions::ROLES as $role) {
            $matrix[$role] = array_fill_keys(Permissions::KEYS, false);
        }
        foreach ($rows as $row) {
            if (isset($matrix[$row->role]) && array_key_exists($row->permission_key, $matrix[$row->role])) {
                $matrix[$row->role][$row->permission_key] = (bool) $row->allowed;
            }
        }
        $matrix['super_admin'] = array_fill_keys(Permissions::KEYS, true);

        return $matrix;
    }
}
