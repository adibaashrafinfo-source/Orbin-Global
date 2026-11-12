<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: permission:key1|key2 → the user needs ANY of the listed keys (read from role_permissions).
 */
class EnsurePermission
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $keys): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw new ApiException('UNAUTHENTICATED', 401);
        }
        $needed = explode('|', $keys);
        if (! $this->permissions->userHasAny($user, $needed)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
