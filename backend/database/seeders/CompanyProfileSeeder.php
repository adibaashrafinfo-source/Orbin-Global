<?php

namespace Database\Seeders;

use App\Models\CompanyProfile;
use Illuminate\Database\Seeder;

/**
 * Singleton row. Address and phones stay empty until the owner fills them, which keeps invoice generation blocked.
 */
class CompanyProfileSeeder extends Seeder
{
    public function run(): void
    {
        CompanyProfile::withoutEvents(function (): void {
            CompanyProfile::query()->firstOrCreate(
                ['singleton' => 1],
                [
                    'legal_name' => 'Orbin Global',
                    'tagline' => 'Trust. Growth. Excellence.',
                    'logo_url' => '/assets/brand/orbin-logo.jpg',
                    'logo_storage_path' => 'assets/brand/orbin-logo.jpg',
                    'address_line1' => '',
                    'country' => 'Bangladesh',
                    'phones' => [],
                ],
            );
        });
    }
}
