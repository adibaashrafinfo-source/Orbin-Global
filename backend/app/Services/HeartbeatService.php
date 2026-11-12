<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** Cron heartbeat: the scheduler stamps this every minute; the health page shows "Last cron run". */
class HeartbeatService
{
    private const CACHE_KEY = 'orbin.scheduler.heartbeat';

    public function beat(): void
    {
        $now = CarbonImmutable::now()->toIso8601String();
        Cache::forever(self::CACHE_KEY, $now);
        @file_put_contents(storage_path('framework/scheduler-heartbeat'), $now);
    }

    public function last(): ?CarbonImmutable
    {
        $value = Cache::get(self::CACHE_KEY);
        if (! is_string($value)) {
            $file = storage_path('framework/scheduler-heartbeat');
            $value = is_file($file) ? trim((string) file_get_contents($file)) : null;
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function isHealthy(int $maxAgeSeconds = 120): bool
    {
        $last = $this->last();

        return $last !== null && $last->diffInSeconds(CarbonImmutable::now()) <= $maxAgeSeconds;
    }
}
