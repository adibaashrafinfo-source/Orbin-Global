<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\VerifyPinRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PermissionService;
use App\Services\SettingsService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** FR-101: Sanctum SPA cookie login, password reset, idle-lock PIN verify, profile. */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PermissionService $permissions,
        private readonly SettingsService $settings,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $key = 'login:'.Str::lower($request->string('email')).'|'.$request->ip();
        $max = (int) config('orbin.login_max_attempts');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiException('AUTH_THROTTLED', 429);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', Str::lower($request->string('email')))->first();
        if ($user === null || ! Hash::check($request->string('password'), $user->password_hash)) {
            RateLimiter::hit($key, (int) config('orbin.login_decay_seconds'));
            $this->audit->log('login_failed', 'users', $user?->id, null, ['email' => $request->string('email')], actorId: null);
            throw new ApiException('AUTH_INVALID_CREDENTIALS', 422);
        }
        if (! $user->is_active) {
            RateLimiter::hit($key, (int) config('orbin.login_decay_seconds'));
            throw new ApiException('AUTH_INACTIVE', 403);
        }

        RateLimiter::clear($key);
        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        User::withoutEvents(fn () => $user->forceFill(['last_login_at' => now()])->save());
        $this->audit->log('login', 'users', $user->id, null, ['email' => $user->email], actorId: $user->id);

        return $this->sessionPayload($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($user) {
            $this->audit->log('logout', 'users', $user->id, actorId: $user->id);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->sessionPayload($user);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $key = 'forgot:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ApiException('AUTH_THROTTLED', 429);
        }
        RateLimiter::hit($key, 60);
        // Always respond the same way so emails cannot be enumerated.
        Password::broker()->sendResetLink(['email' => Str::lower($request->string('email'))]);

        return response()->json(['data' => ['sent' => true]]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            [
                'email' => Str::lower($request->string('email')),
                'password' => $request->string('password'),
                'password_confirmation' => $request->string('password_confirmation'),
                'token' => $request->string('token'),
            ],
            function (User $user, string $password): void {
                User::withoutEvents(function () use ($user, $password): void {
                    $user->forceFill([
                        'password_hash' => Hash::make($password),
                        'must_change_password' => false,
                        'remember_token' => Str::random(60),
                    ])->save();
                });
                $this->audit->log('password_reset', 'users', $user->id, actorId: $user->id);
                event(new PasswordReset($user));
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException('AUTH_RESET_INVALID', 422, '', ['token' => ['errors.AUTH_RESET_INVALID']]);
        }

        return response()->json(['data' => ['reset' => true]]);
    }

    /** Idle-lock unlock and manager override both verify the user's own PIN here (lock after N failures). */
    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->override_pin_hash === null) {
            throw new ApiException('PIN_NOT_SET', 422);
        }
        if ($user->pin_locked_until !== null && $user->pin_locked_until->isFuture()) {
            throw new ApiException('PIN_LOCKED', 423);
        }
        if (! Hash::check($request->string('pin'), $user->override_pin_hash)) {
            $attempts = $user->pin_failed_attempts + 1;
            $limit = (int) $this->settings->get('pin_attempt_limit');
            $data = ['pin_failed_attempts' => $attempts];
            if ($attempts >= $limit) {
                $data['pin_locked_until'] = now()->addMinutes(15);
                $data['pin_failed_attempts'] = 0;
            }
            User::withoutEvents(fn () => $user->forceFill($data)->save());
            $this->audit->log('pin_failed', 'users', $user->id, null, ['attempts' => $attempts], actorId: $user->id);
            throw new ApiException($attempts >= $limit ? 'PIN_LOCKED' : 'PIN_INVALID', $attempts >= $limit ? 423 : 422);
        }
        User::withoutEvents(fn () => $user->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save());

        return response()->json(['data' => ['verified' => true]]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->fill($request->validated())->save();

        return $this->sessionPayload($user->refresh());
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($request->string('current_password'), $user->password_hash)) {
            throw new ApiException('VALIDATION', 422, '', ['current_password' => ['errors.AUTH_INVALID_CREDENTIALS']]);
        }
        User::withoutEvents(function () use ($user, $request): void {
            $user->forceFill(['password_hash' => Hash::make($request->string('password')), 'must_change_password' => false])->save();
        });
        $this->audit->log('password_change', 'users', $user->id, actorId: $user->id);

        return $this->sessionPayload($user->refresh());
    }

    private function sessionPayload(User $user): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'permissions' => $this->permissions->keysForRole($user->role),
                'settings' => [
                    'idle_lock_minutes' => $this->settings->get('idle_lock_minutes'),
                    'numeral_system' => $this->settings->get('numeral_system'),
                    'week_start' => $this->settings->get('week_start'),
                ],
            ],
        ]);
    }
}
