<?php

namespace Tests\Feature;

use App\Services\SettingsService;
use Tests\TestCase;

class AppSettingsTest extends TestCase
{
    public function test_defaults_match_spec_values(): void
    {
        $this->actingAsRole('cashier');
        $res = $this->getJson('/api/v1/settings/app')->assertOk();
        $spec = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/orbin-pos-spec.json'), true)['appSettingsDefaults'];
        foreach ($spec as $key => $value) {
            $this->assertSame($value, $res->json("data.{$key}"), "default for {$key}");
            $this->assertArrayHasKey($key, SettingsService::SCHEMA);
        }
        // Settings added after the spec, each recorded in docs/decisions.md.
        $added = ['allow_negative_account_balance', 'expense_approval_threshold'];
        $this->assertSame(count($spec) + count($added), count(SettingsService::SCHEMA));
        $this->assertSame([], array_diff($added, array_keys(SettingsService::SCHEMA)));
        $res->assertJsonPath('meta.schema.vat_mode.options', ['exclusive', 'inclusive']);
    }

    public function test_update_validates_and_audits(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson('/api/v1/settings/app', ['cashier_max_discount_percent' => 10, 'allow_negative_stock' => true])
            ->assertOk()
            ->assertJsonPath('data.cashier_max_discount_percent', 10)
            ->assertJsonPath('data.allow_negative_stock', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'entity_table' => 'app_settings']);

        $this->putJson('/api/v1/settings/app', ['vat_mode' => 'weird'])->assertStatus(422)->assertJsonStructure(['fields' => ['vat_mode']]);
        $this->putJson('/api/v1/settings/app', ['cashier_max_discount_percent' => 101])->assertStatus(422);
        $this->putJson('/api/v1/settings/app', ['not_a_setting' => 1])->assertOk()->assertJsonMissingPath('data.not_a_setting');
        // The lock date is changed only through POST settings/period-lock, never through the general form.
        $this->putJson('/api/v1/settings/app', ['period_lock_date' => '2026-01-31'])->assertOk()->assertJsonPath('data.period_lock_date', null);
    }
}
