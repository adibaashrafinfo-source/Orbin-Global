<?php

namespace Tests\Feature;

use App\Services\HeartbeatService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HealthTest extends TestCase
{
    protected function tearDown(): void
    {
        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
        }
        parent::tearDown();
    }

    public function test_report_structure_and_cron_heartbeat(): void
    {
        $this->actingAsRole('super_admin');
        $res = $this->getJson('/api/v1/admin/health')->assertOk();
        $res->assertJsonStructure(['data' => ['overall', 'app_version', 'maintenance', 'last_cron_at', 'checks' => [
            'php', 'extensions', 'limits', 'database', 'writable', 'env_safety', 'https', 'cron', 'queue', 'disk', 'backup', 'migrations',
        ]]]);
        $this->assertSame('ok', $res->json('data.checks.php.status'));
        $this->assertSame('ok', $res->json('data.checks.database.status'));
        $this->assertSame('ok', $res->json('data.checks.migrations.status'));
        $this->assertSame('ok', $res->json('data.checks.extensions.status'), json_encode($res->json('data.checks.extensions')));

        Artisan::call('system:heartbeat');
        $this->assertTrue(app(HeartbeatService::class)->isHealthy());
        $this->getJson('/api/v1/admin/health')->assertOk()->assertJsonPath('data.checks.cron.status', 'ok');
    }

    public function test_actions_are_audited(): void
    {
        $this->actingAsRole('super_admin');
        $this->postJson('/api/v1/admin/health/clear-caches')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_action']);

        $res = $this->postJson('/api/v1/admin/health/maintenance', ['enabled' => true])->assertOk();
        $this->assertTrue(app()->isDownForMaintenance());
        $this->assertStringContainsString('/', $res->json('data.bypass_url'));
        $this->postJson('/api/v1/admin/health/maintenance', ['enabled' => false])->assertOk();
        $this->assertFalse(app()->isDownForMaintenance());

        $this->postJson('/api/v1/admin/health/run-migrations')->assertOk()->assertJsonPath('data.ok', true);
    }

    public function test_scheduler_registers_heartbeat_and_queue_drain(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");
        $this->assertStringContainsString('system:heartbeat', $events);
        $this->assertStringContainsString('queue:work', $events);
    }
}
