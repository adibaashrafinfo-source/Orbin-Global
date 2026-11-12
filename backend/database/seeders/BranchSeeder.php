<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::withoutEvents(function (): void {
            Branch::query()->firstOrCreate(
                ['code' => 'HQ'],
                ['name' => 'Head Office / Main Shop', 'is_default' => true, 'is_active' => true],
            );
        });
    }
}
