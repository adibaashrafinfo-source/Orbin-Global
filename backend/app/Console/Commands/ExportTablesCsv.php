<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NFR-070: every table as a CSV file, the second copy of the data next to the SQL dump. Rows are read in chunks and written as they
 * come, so the command stays within a shared host's memory. Sessions, cache and queue tables are skipped: they hold no business data.
 */
class ExportTablesCsv extends Command
{
    private const SKIP = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations'];

    private const CHUNK = 1000;

    protected $signature = 'orbin:export-csv {--dir= : Folder for the files (default BACKUP_DIR/csv-YYYYMMDD-HHMMSS)}';

    protected $description = 'Write every business table to its own CSV file (NFR-070)';

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('dir') ?: env('BACKUP_DIR', base_path('../backups'))), '/');
        $dir = $this->option('dir') ?: $base.'/csv-'.now('Asia/Dhaka')->format('Ymd-His');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            $this->error("Cannot create the folder: {$dir}");

            return self::FAILURE;
        }

        $written = 0;
        // Only this database's own tables: the server listing also includes every other database the account can see.
        $tables = DB::select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name");
        foreach ($tables as $row) {
            $table = (string) $row->name;
            if (in_array($table, self::SKIP, true)) {
                continue;
            }
            $file = $dir.'/'.$table.'.csv';
            $out = fopen($file, 'w');
            $header = false;
            $write = function (array $row) use ($out, &$header): void {
                if (! $header) {
                    fputcsv($out, array_keys($row));
                    $header = true;
                }
                fputcsv($out, array_map(fn ($v) => $this->cell($v), $row));
            };

            if (Schema::hasColumn($table, 'id')) {
                // Keyset paging on id keeps every chunk cheap, even on the largest tables.
                $last = 0;
                do {
                    $rows = DB::table($table)->where('id', '>', $last)->orderBy('id')->limit(self::CHUNK)->get();
                    foreach ($rows as $row) {
                        $arr = (array) $row;
                        $write($arr);
                        $last = (int) $arr['id'];
                    }
                } while ($rows->count() === self::CHUNK);
            } else {
                // Tables without an id (pivots, the cache) are streamed in one pass.
                foreach (DB::table($table)->cursor() as $row) {
                    $write((array) $row);
                }
            }

            if (! $header) {
                fputcsv($out, Schema::getColumnListing($table));
            }
            fclose($out);
            @chmod($file, 0600);
            $written++;
        }

        $this->info("Wrote {$written} CSV file(s) to {$dir}");

        return self::SUCCESS;
    }

    /** Dates and JSON are written as text; binary (none expected) as base64 so no byte is lost. */
    private function cell(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v) && ! mb_check_encoding($v, 'UTF-8')) {
            return 'base64:'.base64_encode($v);
        }

        return (string) $v;
    }
}
