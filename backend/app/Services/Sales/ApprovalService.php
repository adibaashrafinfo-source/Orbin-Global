<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PermissionService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Hash;

/**
 * BR-020: a manager's PIN lets a sale go below the floor price, past the discount limit or over a credit limit.
 * Wrong guesses are counted against the person typing (the cashier), who is locked out after the setting's limit (NFR-010).
 */
class ApprovalService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<int, string>  $anyOf  permission keys; the approver must hold at least one
     */
    public function approver(User $actor, ?string $pin, array $anyOf): User
    {
        if ($pin === null || trim($pin) === '') {
            throw new ApiException('PIN_REQUIRED', 422);
        }
        if ($actor->pin_locked_until !== null && $actor->pin_locked_until->isFuture()) {
            throw new ApiException('PIN_LOCKED', 423);
        }
        $candidates = User::query()->where('is_active', true)->whereNotNull('override_pin_hash')->orderBy('id')->get();
        foreach ($candidates as $user) {
            if ($this->permissions->userHasAny($user, $anyOf) && Hash::check($pin, (string) $user->override_pin_hash)) {
                User::withoutEvents(fn () => $actor->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save());

                return $user;
            }
        }
        $attempts = $actor->pin_failed_attempts + 1;
        $limit = (int) $this->settings->get('pin_attempt_limit');
        $data = ['pin_failed_attempts' => $attempts];
        if ($attempts >= $limit) {
            $data = ['pin_failed_attempts' => 0, 'pin_locked_until' => now()->addMinutes(15)];
        }
        User::withoutEvents(fn () => $actor->forceFill($data)->save());
        $this->audit->log('pin_failed', 'users', $actor->id, null, ['attempts' => $attempts, 'context' => 'override'], actorId: $actor->id);

        throw new ApiException($attempts >= $limit ? 'PIN_LOCKED' : 'PIN_INVALID', $attempts >= $limit ? 423 : 422);
    }
}
