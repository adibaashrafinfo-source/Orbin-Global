<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExportTablesCsvTest extends TestCase
{
    public function test_every_business_table_gets_a_csv_with_a_header_and_its_rows(): void
    {
        $dir = sys_get_temp_dir().'/orbin-csv-'.bin2hex(random_bytes(4));
        try {
            $this->artisan('orbin:export-csv', ['--dir' => $dir])->assertSuccessful();

            $this->assertFileExists($dir.'/sales.csv');
            $this->assertFileExists($dir.'/stock_movements.csv');
            $this->assertFileDoesNotExist($dir.'/sessions.csv');
            $this->assertFileDoesNotExist($dir.'/migrations.csv');

            $lines = file($dir.'/branches.csv', FILE_IGNORE_NEW_LINES);
            $this->assertStringStartsWith('id,', (string) $lines[0]);
            $this->assertGreaterThan(1, count($lines), 'the seeded branch is written');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
