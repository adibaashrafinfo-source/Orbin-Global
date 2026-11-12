<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditService;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_audit_rows_are_append_only(): void
    {
        $row = app(AuditService::class)->log('create', 'branches', 1, null, ['name' => 'x']);
        try {
            $row->update(['action' => 'tampered']);
            $this->fail('update must throw');
        } catch (LogicException) {
        }
        try {
            $row->delete();
            $this->fail('delete must throw');
        } catch (LogicException) {
        }
        $this->assertDatabaseHas('audit_logs', ['id' => $row->id, 'action' => 'create']);
    }

    public function test_listing_filters_and_export(): void
    {
        $admin = $this->actingAsRole('super_admin');
        $this->putJson('/api/v1/settings/app', ['cashier_max_discount_percent' => 8])->assertOk();
        $this->postJson('/api/v1/branches', ['code' => 'B9', 'name' => 'Nine'])->assertCreated();

        $res = $this->getJson('/api/v1/audit-logs?per_page=50')->assertOk();
        $this->assertGreaterThanOrEqual(2, $res->json('meta.total'));
        $this->assertSame($admin->full_name, $res->json('data.0.actor.full_name'));

        $this->getJson('/api/v1/audit-logs?filter[entity_table]=branches&filter[action]=create')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.after.code', 'B9');
        $this->getJson('/api/v1/audit-logs?filter[entity_table]=branches&filter[from]=2099-01-01')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/audit-logs?per_page=500')->assertStatus(422);

        $export = $this->get('/api/v1/audit-logs/export?filter[entity_table]=branches');
        $export->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('content-type'));
        $csv = $export->streamedContent();
        $this->assertStringContainsString('entity_table', $csv);
        $this->assertStringContainsString('B9', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export', 'entity_table' => 'audit_logs']);
        $this->assertSame(0, AuditLog::query()->whereNull('request_id')->where('action', 'export')->count());
    }
}
