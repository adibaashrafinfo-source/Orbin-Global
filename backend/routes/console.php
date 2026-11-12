<?php

use Illuminate\Support\Facades\Schedule;

// One cPanel cron entry runs `php artisan schedule:run` every minute (DEPLOY_CPANEL.md §9).
Schedule::command('system:heartbeat')->everyMinute();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(5);
// db:backup (02:30 Asia/Dhaka), integrity:check (03:00) and stock:alerts (hourly) are registered in P2/P6
// together with the commands themselves.
