<?php

namespace Tests\Feature;

use App\Models\NumberSequence;
use App\Services\NumberService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NumberingTest extends TestCase
{
    public function test_list_and_update_formats(): void
    {
        $this->actingAsRole('super_admin');
        $res = $this->getJson('/api/v1/settings/numbering')->assertOk();
        $this->assertCount(16, $res->json('data'));
        $invoice = collect($res->json('data'))->firstWhere('key', 'invoice');
        $this->assertSame('ORB-INV', $invoice['prefix']);
        $this->assertMatchesRegularExpression('/^ORB-INV-\d{4}-000001$/', $invoice['example']);

        $this->putJson('/api/v1/settings/numbering/'.$invoice['id'], ['prefix' => 'OG-INV', 'date_token' => 'YYYY', 'padding' => 5, 'reset_policy' => 'yearly', 'next_value' => 100])
            ->assertOk()->assertJsonPath('data.prefix', 'OG-INV')->assertJsonPath('data.next_value', 100)
            ->assertJsonPath('data.example', 'OG-INV-'.now('Asia/Dhaka')->format('Y').'-00100');
        // next_value can never move backwards (gap-free, never reused)
        $this->putJson('/api/v1/settings/numbering/'.$invoice['id'], ['prefix' => 'OG-INV', 'date_token' => 'YYYY', 'padding' => 5, 'reset_policy' => 'yearly', 'next_value' => 5])
            ->assertOk()->assertJsonPath('data.next_value', 100);
        $this->putJson('/api/v1/settings/numbering/'.$invoice['id'], ['prefix' => 'bad prefix', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'])
            ->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'entity_table' => 'number_sequences']);
    }

    public function test_numbers_are_sequential_and_gap_free_and_reset_per_period(): void
    {
        $svc = app(NumberService::class);
        $date = CarbonImmutable::create(2026, 3, 5, 12, 0, 0, 'Asia/Dhaka');
        $numbers = DB::transaction(fn () => [
            $svc->next('invoice', null, $date),
            $svc->next('invoice', null, $date),
            $svc->next('invoice', null, $date),
        ]);
        $this->assertSame(['ORB-INV-2603-000001', 'ORB-INV-2603-000002', 'ORB-INV-2603-000003'], $numbers);

        // yearly reset: a new year starts again at 1, the old year keeps its counter
        $next = DB::transaction(fn () => $svc->next('invoice', null, $date->addYear()));
        $this->assertSame('ORB-INV-2703-000001', $next);
        $again = DB::transaction(fn () => $svc->next('invoice', null, $date));
        $this->assertSame('ORB-INV-2603-000004', $again);

        // never-reset sequences count across years
        $c1 = DB::transaction(fn () => $svc->next('customer', null, $date));
        $c2 = DB::transaction(fn () => $svc->next('customer', null, $date->addYears(2)));
        $this->assertSame(['CUS-000001', 'CUS-000002'], [$c1, $c2]);

        $this->assertSame(2, NumberSequence::query()->where('key', 'invoice')->where('period_key', '!=', 'all')->count());
    }

    public function test_concurrent_callers_never_share_or_skip_a_number(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl is required to prove the FOR UPDATE guard; install it for the test environment.');
        }
        // Use a committed sequence row on a separate connection so forked children (own connections) can see it.
        config(['database.connections.mysql_raw' => config('database.connections.mysql')]);
        $raw = DB::connection('mysql_raw');
        $raw->table('number_sequences')->where('key', 'zz_concurrency')->delete();
        $raw->table('number_sequences')->insert([
            'key' => 'zz_concurrency', 'branch_id' => null, 'prefix' => 'CC', 'date_token' => '', 'padding' => 4,
            'reset_policy' => 'never', 'period_key' => 'all', 'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        array_map('unlink', glob($dir.'/cc-*.txt') ?: []);

        $workers = 6;
        $perWorker = 5;
        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                // Child: keep the inherited PDO alive (closing it would send QUIT on the parent's shared socket),
                // open a fresh connection, take N numbers in separate transactions, record them, hard-exit.
                $inherited = DB::connection()->getPdo();
                DB::reconnect('mysql');
                $svc = new NumberService;
                $got = [];
                for ($i = 0; $i < $perWorker; $i++) {
                    $got[] = DB::transaction(function () use ($svc) {
                        $n = $svc->next('zz_concurrency', null);
                        usleep(random_int(100, 1500));

                        return $n;
                    });
                }
                file_put_contents($dir.'/cc-'.getmypid().'.txt', implode("\n", $got));
                unset($got);
                posix_kill(getmypid(), SIGKILL); // $inherited is never destructed
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $all = [];
        foreach (glob($dir.'/cc-*.txt') ?: [] as $file) {
            $all = array_merge($all, array_filter(explode("\n", (string) file_get_contents($file))));
            unlink($file);
        }
        $expected = array_map(fn ($i) => 'CC-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), range(1, $workers * $perWorker));
        sort($all);
        $this->assertSame($expected, $all, 'every number must be issued exactly once with no gaps');
        $this->assertSame($workers * $perWorker + 1, (int) $raw->table('number_sequences')->where('key', 'zz_concurrency')->value('next_value'));
        $raw->table('number_sequences')->where('key', 'zz_concurrency')->delete();
    }
}
