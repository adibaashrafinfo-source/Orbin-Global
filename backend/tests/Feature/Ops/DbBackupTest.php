<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Nightly backup (DEPLOY_CPANEL.md §10): a gzipped dump, old dumps removed, nothing secret on the command line. */
class DbBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/orbin-backup-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_it_writes_a_gzipped_dump_that_restores_the_schema(): void
    {
        $this->artisan('db:backup', ['--dir' => $this->dir])->assertSuccessful();

        $files = glob($this->dir.'/*.sql.gz');
        $this->assertCount(1, $files);
        $sql = gzdecode((string) file_get_contents($files[0]));
        $this->assertStringContainsString('CREATE TABLE `sales`', $sql);
        $this->assertStringContainsString('CREATE TABLE `stock_movements`', $sql);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
    }

    public function test_dumps_older_than_the_retention_period_are_removed(): void
    {
        $old = $this->dir.'/old.sql.gz';
        $recent = $this->dir.'/recent.sql.gz';
        file_put_contents($old, 'x');
        file_put_contents($recent, 'x');
        touch($old, now()->subDays(20)->getTimestamp());
        touch($recent, now()->subDays(2)->getTimestamp());

        $this->artisan('db:backup', ['--dir' => $this->dir, '--keep-days' => 14])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_a_failed_dump_leaves_no_partial_file_and_fails(): void
    {
        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 1, 'database.connections.mysql.password' => 'wrong']);
        $this->artisan('db:backup', ['--dir' => $this->dir])->assertFailed();

        $this->assertSame([], glob($this->dir.'/*.sql.gz'));
    }
}
