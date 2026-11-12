<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        // Pure API: unauthenticated callers always get the JSON 401, never a redirect to a login route.
        $middleware->redirectGuestsTo(fn () => null);
        // Admin → Health stays reachable in maintenance mode so the admin can switch it off again (FR-112).
        $middleware->preventRequestsDuringMaintenance(except: ['api/v1/admin/health', 'api/v1/admin/health/*', 'api/v1/auth/*', 'sanctum/csrf-cookie']);
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['permission' => EnsurePermission::class, 'active' => EnsureUserIsActive::class]);
        $middleware->trustProxies(at: '*'); // cPanel/Apache terminates TLS in front of PHP
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $wantsJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();
        $body = function (string $code, string $message, array $fields = []): array {
            return [
                'code' => $code,
                'message' => $message,
                'fields' => (object) $fields,
                'request_id' => app()->bound('request_id') ? app('request_id') : null,
            ];
        };

        $exceptions->render(function (ApiException $e, Request $request) use ($wantsJson, $body) {
            if ($wantsJson($request)) {
                return response()->json($body($e->errorCode, $e->getMessage(), $e->fields), $e->status);
            }

            return null;
        });
        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson, $body) {
            if ($wantsJson($request)) {
                return response()->json($body('VALIDATION', 'errors.VALIDATION', $e->errors()), 422);
            }

            return null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson, $body) {
            if ($wantsJson($request)) {
                return response()->json($body('UNAUTHENTICATED', 'errors.UNAUTHENTICATED'), 401);
            }

            return null;
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) use ($wantsJson, $body) {
            if ($wantsJson($request)) {
                return response()->json($body('FORBIDDEN', 'errors.FORBIDDEN'), 403);
            }

            return null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($wantsJson, $body) {
            if ($wantsJson($request)) {
                return response()->json($body('AUTH_THROTTLED', 'errors.AUTH_THROTTLED'), 429, $e->getHeaders());
            }

            return null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($wantsJson, $body) {
            if ($wantsJson($request)) {
                return response()->json($body('NOT_FOUND', 'errors.NOT_FOUND'), 404);
            }

            return null;
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsJson, $body) {
            if ($wantsJson($request)) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    403 => 'FORBIDDEN',
                    405 => 'METHOD_NOT_ALLOWED',
                    419 => 'CSRF_MISMATCH',
                    503 => 'MAINTENANCE',
                    default => 'HTTP_'.$status,
                };

                return response()->json($body($code, 'errors.'.$code), $status, $e->getHeaders());
            }

            return null;
        });
        $exceptions->render(function (Throwable $e, Request $request) use ($wantsJson, $body) {
            if ($wantsJson($request) && ! config('app.debug')) {
                report($e);

                return response()->json($body('SERVER_ERROR', 'errors.unknown'), 500);
            }

            return null;
        });
    })->create();
