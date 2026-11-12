<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hosting health checks for Admin → Health (FR-112). Each check returns {status: ok|warn|fail, detail}.
 */
class HealthService
{
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'intl', 'gd', 'zip', 'fileinfo', 'ctype', 'json', 'openssl', 'tokenizer', 'xml', 'curl',
    ];

    /** bcmath is optional: the Money helper uses integer paisa. */
    public const OPTIONAL_EXTENSIONS = ['bcmath'];

    public function __construct(private readonly HeartbeatService $heartbeat) {}

    /** @return array<string, mixed> */
    public function report(bool $isSecure): array
    {
        $checks = [
            'php' => $this->checkPhp(),
            'extensions' => $this->checkExtensions(),
            'limits' => $this->checkLimits(),
            'database' => $this->checkDatabase(),
            'writable' => $this->checkWritable(),
            'env_safety' => $this->checkEnvSafety(),
            'https' => $this->checkHttps($isSecure),
            'cron' => $this->checkCron(),
            'queue' => $this->checkQueue(),
            'disk' => $this->checkDisk(),
            'backup' => $this->checkBackup(),
            'migrations' => $this->checkMigrations(),
        ];
        $overall = 'ok';
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') {
                $overall = 'fail';
                break;
            }
            if ($c['status'] === 'warn') {
                $overall = 'warn';
            }
        }

        return [
            'overall' => $overall,
            'app_version' => (string) config('orbin.version'),
            'app_env' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'maintenance' => app()->isDownForMaintenance(),
            'last_cron_at' => $this->heartbeat->last()?->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    private function result(string $status, string $detail, array $extra = []): array
    {
        return ['status' => $status, 'detail' => $detail] + $extra;
    }

    private function checkPhp(): array
    {
        $ok = version_compare(PHP_VERSION, '8.2.0', '>=');

        return $this->result($ok ? 'ok' : 'fail', PHP_VERSION, ['required' => '8.2+']);
    }

    private function checkExtensions(): array
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn ($e) => ! extension_loaded($e)));
        $optionalMissing = array_values(array_filter(self::OPTIONAL_EXTENSIONS, fn ($e) => ! extension_loaded($e)));

        return $this->result(
            $missing === [] ? 'ok' : 'fail',
            $missing === [] ? 'All required extensions loaded' : 'Missing: '.implode(', ', $missing),
            ['missing' => $missing, 'optional_missing' => $optionalMissing],
        );
    }

    private function checkLimits(): array
    {
        $limits = [
            'memory_limit' => [ini_get('memory_limit'), 256 * 1024 * 1024],
            'max_execution_time' => [ini_get('max_execution_time'), 60],
            'upload_max_filesize' => [ini_get('upload_max_filesize'), 16 * 1024 * 1024],
            'post_max_size' => [ini_get('post_max_size'), 24 * 1024 * 1024],
        ];
        $bad = [];
        $values = [];
        foreach ($limits as $name => [$raw, $min]) {
            $values[$name] = (string) $raw;
            $bytes = $name === 'max_execution_time' ? (int) $raw : $this->toBytes((string) $raw);
            $unlimited = $bytes <= 0; // -1 or 0 means unlimited
            if (! $unlimited && $bytes < $min) {
                $bad[] = $name;
            }
        }

        return $this->result($bad === [] ? 'ok' : 'warn', $bad === [] ? 'Within recommended limits' : 'Low: '.implode(', ', $bad), ['values' => $values]);
    }

    private function checkDatabase(): array
    {
        try {
            $version = (string) DB::selectOne('select version() as v')->v;
            $isMaria = stripos($version, 'mariadb') !== false;
            preg_match('/(\d+)\.(\d+)/', $version, $m);
            $major = (int) ($m[1] ?? 0);
            $minor = (int) ($m[2] ?? 0);
            $ok = $isMaria ? ($major > 10 || ($major === 10 && $minor >= 5)) : $major >= 8;

            return $this->result($ok ? 'ok' : 'fail', $version, ['driver' => DB::getDriverName()]);
        } catch (Throwable $e) {
            return $this->result('fail', 'Cannot connect: '.$e->getMessage());
        }
    }

    private function checkWritable(): array
    {
        $paths = [
            'storage' => storage_path(),
            'storage/logs' => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'storage/app/private' => storage_path('app/private'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
            'public/uploads' => public_path('uploads'),
        ];
        $bad = [];
        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $label;
            }
        }

        return $this->result($bad === [] ? 'ok' : 'fail', $bad === [] ? 'All writable' : 'Not writable: '.implode(', ', $bad), ['paths' => array_keys($paths)]);
    }

    private function checkEnvSafety(): array
    {
        $envPath = base_path('.env');
        $publicPath = realpath(public_path()) ?: public_path();
        $insidePublic = str_starts_with(realpath(dirname($envPath)) ?: '', $publicPath);
        $debug = (bool) config('app.debug') && config('app.env') === 'production';
        if ($insidePublic) {
            return $this->result('fail', '.env is inside the public document root');
        }
        if ($debug) {
            return $this->result('fail', 'APP_DEBUG is true in production');
        }

        return $this->result('ok', '.env outside web root; APP_DEBUG='.((bool) config('app.debug') ? 'true' : 'false'));
    }

    private function checkHttps(bool $isSecure): array
    {
        $urlHttps = str_starts_with((string) config('app.url'), 'https://');
        if ($isSecure || app()->environment('local', 'testing')) {
            return $this->result('ok', $isSecure ? 'Request served over HTTPS' : 'Local environment');
        }

        return $this->result($urlHttps ? 'warn' : 'fail', 'Request not over HTTPS');
    }

    private function checkCron(): array
    {
        $last = $this->heartbeat->last();
        if ($last === null) {
            return $this->result('fail', 'Scheduler never ran', ['last_run_at' => null]);
        }
        $age = (int) $last->diffInSeconds(now());

        return $this->result($age <= 120 ? 'ok' : 'fail', $age <= 120 ? 'Ran '.$age.'s ago' : 'Last run '.$age.'s ago', ['last_run_at' => $last->toIso8601String(), 'age_seconds' => $age]);
    }

    private function checkQueue(): array
    {
        try {
            $pending = (int) DB::table('jobs')->count();
            $failed = (int) DB::table('failed_jobs')->count();

            return $this->result($failed === 0 ? 'ok' : 'warn', "{$pending} pending, {$failed} failed", ['pending' => $pending, 'failed' => $failed, 'driver' => config('queue.default')]);
        } catch (Throwable $e) {
            return $this->result('warn', 'Queue tables unavailable');
        }
    }

    private function checkDisk(): array
    {
        $free = @disk_free_space(base_path());
        if ($free === false) {
            return $this->result('warn', 'Unknown');
        }
        $gb = $free / (1024 ** 3);

        return $this->result($gb >= 1 ? 'ok' : 'warn', number_format($gb, 2).' GB free', ['free_bytes' => (int) $free]);
    }

    private function checkBackup(): array
    {
        // Nightly db:backup arrives in P6; until then the check is informational.
        $dir = (string) config('orbin.backup_dir');
        $latest = null;
        if ($dir !== '' && is_dir($dir)) {
            foreach (glob($dir.'/*.sql.gz') ?: [] as $file) {
                $mtime = filemtime($file) ?: 0;
                if ($latest === null || $mtime > $latest) {
                    $latest = $mtime;
                }
            }
        }
        if ($latest === null) {
            return $this->result('warn', 'No backup found yet', ['last_backup_at' => null]);
        }
        $age = time() - $latest;

        return $this->result($age <= 36 * 3600 ? 'ok' : 'warn', 'Last backup '.gmdate('Y-m-d H:i', $latest).' UTC', ['last_backup_at' => gmdate('c', $latest)]);
    }

    private function checkMigrations(): array
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            $ran = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $pending = array_values(array_diff(array_keys($files), $ran));

            return $this->result($pending === [] ? 'ok' : 'warn', $pending === [] ? 'Up to date' : count($pending).' pending', ['pending' => $pending]);
        } catch (Throwable $e) {
            return $this->result('fail', 'Cannot read migrations table');
        }
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 ** 3,
            'm' => $num * 1024 ** 2,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
