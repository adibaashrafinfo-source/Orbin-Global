<?php

use Illuminate\Support\Facades\Schedule;

// One cPanel cron entry runs `php artisan schedule:run` every minute (DEPLOY_CPANEL.md §9).
Schedule::command('system:heartbeat')->everyMinute();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(5);
Schedule::command('integrity:check')->dailyAt('03:00')->timezone('Asia/Dhaka');
Schedule::command('stock:alerts')->hourly();
Schedule::command('db:backup')->dailyAt('02:30')->timezone('Asia/Dhaka')->withoutOverlapping(30);
