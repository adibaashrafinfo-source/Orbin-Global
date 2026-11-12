<?php

return [
    'version' => env('APP_VERSION', '0.1.0'),

    // Nightly dumps live outside the web root (DEPLOY_CPANEL.md §10). Resolved relative to the account home.
    'backup_dir' => env('ORBIN_BACKUP_DIR', dirname(base_path()).'/backups/orbin-pos'),

    // Route-level login throttle per IP and minute. Tests/E2E raise it via ORBIN_LOGIN_RATE_LIMIT; production keeps 10.
    'login_route_per_minute' => (int) env('ORBIN_LOGIN_RATE_LIMIT', 10),

    // Throttling (NFR-010): 5 failed attempts per account+IP then lock.
    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,
];
