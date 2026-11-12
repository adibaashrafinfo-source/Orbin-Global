<?php

namespace App\Providers;

use App\Models\Notification;
use App\Models\User;
use App\Policies\NotificationPolicy;
use App\Policies\UserPolicy;
use App\Services\PermissionService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * One Gate ability per permission key, all backed by role_permissions through PermissionService,
 * plus model policies for ownership rules. super_admin passes every check.
 */
class PermissionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_active) {
                return false;
            }

            return $user->isSuperAdmin() ? true : null;
        });

        foreach (Permissions::KEYS as $key) {
            Gate::define($key, fn (User $user): bool => app(PermissionService::class)->userHas($user, $key));
        }

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
    }
}
