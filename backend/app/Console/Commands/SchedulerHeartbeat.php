<?php

namespace App\Console\Commands;

use App\Services\HeartbeatService;
use Illuminate\Console\Command;

class SchedulerHeartbeat extends Command
{
    protected $signature = 'system:heartbeat';

    protected $description = 'Stamp the scheduler heartbeat shown on Admin → Health (runs every minute from cron)';

    public function handle(HeartbeatService $heartbeat): int
    {
        $heartbeat->beat();

        return self::SUCCESS;
    }
}
