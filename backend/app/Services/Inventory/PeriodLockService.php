<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Services\SettingsService;
use Carbon\CarbonInterface;

/** BR-080: nothing may be created, changed or posted with a document date on or before the lock date. */
class PeriodLockService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function assertOpen(CarbonInterface|string|null $date): void
    {
        $lock = $this->settings->get('period_lock_date');
        if (! $lock || $date === null) {
            return;
        }
        $day = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);
        if ($day <= substr((string) $lock, 0, 10)) {
            throw new ApiException('PERIOD_LOCKED', 422, '', [], ['lock_date' => substr((string) $lock, 0, 10)]);
        }
    }
}
