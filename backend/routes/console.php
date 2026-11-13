<?php

use Illuminate\Support\Facades\Schedule;

// One cPanel cron entry runs `php artisan schedule:run` every minute (DEPLOY_CPANEL.md §9).
Schedule::command('system:heartbeat')->everyMinute();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(5);
Schedule::command('integrity:check')->dailyAt('03:00')->timezone('Asia/Dhaka');
Schedule::command('stock:alerts')->hourly();
// db:backup (02:30 Asia/Dhaka) is registered in P6 together with its command.
