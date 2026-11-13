<?php

namespace Tests\Concerns;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/** Runs a closure in several forked processes at once, each with its own database connection. */
trait RacesProcesses
{
    /**
     * @param  callable(int): string  $work
     * @return array<int, string> sorted results, one per child
     */
    protected function race(int $n, callable $work): array
    {
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $barrier = $dir.'/go-'.uniqid();
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                $inherited = DB::connection()->getPdo(); // keep the parent's socket from being closed under it
                DB::reconnect('mysql');
                $deadline = microtime(true) + 5;
                while (! file_exists($barrier) && microtime(true) < $deadline) {
                    usleep(500);
                }
                try {
                    $result = $work($i);
                } catch (ApiException $e) {
                    $result = $e->errorCode;
                } catch (\Throwable $e) {
                    $result = 'ERROR:'.get_class($e).':'.$e->getMessage();
                }
                file_put_contents($dir."/res-{$i}-".getmypid().'.txt', $result);
                unset($inherited);
                posix_kill(getmypid(), SIGKILL);
                exit(0);
            }
            $pids[] = $pid;
        }
        usleep(150_000);
        touch($barrier);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        @unlink($barrier);
        $results = [];
        foreach (glob($dir.'/res-*.txt') ?: [] as $file) {
            $results[] = (string) file_get_contents($file);
            unlink($file);
        }
        sort($results);

        return $results;
    }
}
