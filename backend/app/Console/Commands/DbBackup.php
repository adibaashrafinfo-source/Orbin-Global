<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Nightly dump of the application database (DEPLOY_CPANEL.md §10). The dump is gzipped into a folder outside the web root,
 * and dumps older than the retention period are deleted. The password is passed through MYSQL_PWD, never on the command line.
 */
class DbBackup extends Command
{
    protected $signature = 'db:backup {--dir= : Folder for the dump (default BACKUP_DIR, else ../backups next to the app)} {--keep-days= : Days to keep dumps (default BACKUP_KEEP_DAYS, else 14)}';

    protected $description = 'Dump the database to a gzipped file outside the web root and delete old dumps';

    public function handle(): int
    {
        $dir = rtrim((string) ($this->option('dir') ?: env('BACKUP_DIR', base_path('../backups'))), '/');
        $keep = (int) ($this->option('keep-days') ?: env('BACKUP_KEEP_DAYS', 14));
        $conn = config('database.connections.'.config('database.default'));

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            $this->error("Cannot create the backup folder: {$dir}");

            return self::FAILURE;
        }

        $file = $dir.'/'.($conn['database'] ?? 'db').'-'.now('Asia/Dhaka')->format('Ymd-His').'.sql.gz';
        $dump = new Process([
            'mysqldump', '--single-transaction', '--routines=false', '--triggers=false', '--no-tablespaces',
            '-h', (string) $conn['host'], '-P', (string) $conn['port'], '-u', (string) $conn['username'], (string) $conn['database'],
        ], null, ['MYSQL_PWD' => (string) $conn['password']], null, 600);

        $dump->run();
        if (! $dump->isSuccessful()) {
            @unlink($file);
            $this->error('mysqldump failed: '.trim($dump->getErrorOutput()));

            return self::FAILURE;
        }

        $gz = gzopen($file, 'wb9');
        gzwrite($gz, $dump->getOutput());
        gzclose($gz);
        @chmod($file, 0600);

        $removed = 0;
        foreach (glob($dir.'/*.sql.gz') ?: [] as $old) {
            if (filemtime($old) < now()->subDays($keep)->getTimestamp()) {
                @unlink($old);
                $removed++;
            }
        }

        $this->info('Backup written: '.basename($file).' ('.number_format((int) filesize($file)).' bytes); removed '.$removed.' old dump(s).');

        return self::SUCCESS;
    }
}
