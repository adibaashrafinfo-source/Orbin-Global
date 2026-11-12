<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'HQ')->firstOrFail();
        Warehouse::withoutEvents(function () use ($branch): void {
            Warehouse::query()->firstOrCreate(
                ['code' => 'SHOW'],
                ['branch_id' => $branch->id, 'name' => 'Showroom', 'kind' => 'showroom', 'is_default' => true, 'is_sellable' => true],
            );
            Warehouse::query()->firstOrCreate(
                ['code' => 'GODOWN'],
                ['branch_id' => $branch->id, 'name' => 'Godown', 'kind' => 'godown', 'is_default' => false, 'is_sellable' => false],
            );
        });
    }
}
