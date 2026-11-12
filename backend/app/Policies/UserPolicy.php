<?php

namespace App\Policies;

use App\Models\User;
use App\Services\PermissionService;

class UserPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->userHas($actor, 'users.manage');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $this->permissions->userHas($actor, 'users.manage');
    }

    public function create(User $actor): bool
    {
        return $this->permissions->userHas($actor, 'users.manage');
    }

    public function update(User $actor, User $target): bool
    {
        return $this->permissions->userHas($actor, 'users.manage');
    }

    /** Only a super admin may create or edit another super admin. */
    public function assignRole(User $actor, string $role): bool
    {
        return $role !== 'super_admin' || $actor->isSuperAdmin();
    }
}
