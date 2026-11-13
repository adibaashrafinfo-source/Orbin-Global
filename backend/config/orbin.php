<?php

return [
    // The release script writes the VERSION file at the application root; a development checkout has none.
    'version' => env('APP_VERSION', is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : '0.1.0'),

    // Nightly dumps live outside the web root (DEPLOY_CPANEL.md §10). Resolved relative to the account home.
    'backup_dir' => env('ORBIN_BACKUP_DIR', dirname(base_path()).'/backups/orbin-pos'),

    // PDF streams are compressed in production; tests switch this off to read text from the PDF.
    'pdf_compress' => (bool) env('ORBIN_PDF_COMPRESS', true),

    // Route-level login throttle per IP and minute. Tests/E2E raise it via ORBIN_LOGIN_RATE_LIMIT; production keeps 10.
    'login_route_per_minute' => (int) env('ORBIN_LOGIN_RATE_LIMIT', 10),

    // Throttling (NFR-010): 5 failed attempts per account+IP then lock.
    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,
];
