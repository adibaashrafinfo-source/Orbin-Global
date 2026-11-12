<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

class AppSettingsSeeder extends Seeder
{
    public function run(): void
    {
        AppSetting::withoutEvents(function (): void {
            foreach (SettingsService::SCHEMA as $key => $def) {
                AppSetting::query()->firstOrCreate(
                    ['key' => $key],
                    ['value' => $def['default'], 'group_name' => $def['group']],
                );
            }
        });
        app(SettingsService::class)->forgetCache();
    }
}
