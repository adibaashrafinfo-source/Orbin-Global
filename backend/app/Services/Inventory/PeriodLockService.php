<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PermissionService;
use App\Services\SettingsService;
use App\Support\Branches;
use Carbon\CarbonInterface;

/** BR-080 and FR-1009: nothing may be created, changed or posted with a document date on or before the lock date. */
class PeriodLockService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
    ) {}

    public function current(): ?string
    {
        $lock = $this->settings->get('period_lock_date');

        return $lock ? substr((string) $lock, 0, 10) : null;
    }

    public function assertOpen(CarbonInterface|string|null $date): void
    {
        $lock = $this->current();
        if ($lock === null || $date === null) {
            return;
        }
        $day = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);
        if ($day <= $lock) {
            throw new ApiException('PERIOD_LOCKED', 422, '', [], ['lock_date' => $lock]);
        }
    }

    /** Lock everything up to and including $date. Moving the lock earlier (or lifting it) reopens closed books, so only the owner may. */
    public function lock(User $user, ?string $date): ?string
    {
        if (! $this->permissions->userHas($user, 'periods.lock')) {
            throw ApiException::forbidden();
        }
        $current = $this->current();
        if ($date !== null && $date > Branches::today()) {
            throw new ApiException('VALIDATION', 422, '', ['lock_date' => ['errors.DATE_IN_FUTURE']]);
        }
        $backwards = $current !== null && ($date === null || $date < $current);
        if ($backwards && ! $user->isSuperAdmin()) {
            throw ApiException::forbidden();
        }
        $this->settings->update(['period_lock_date' => $date]);
        $this->audit->log('period_lock', 'app_settings', null, ['period_lock_date' => $current], ['period_lock_date' => $date]);

        return $date;
    }
}
