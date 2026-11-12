<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Starter catalog data (spec seedData). Idempotent; admin edits are never overwritten. */
class CatalogSeeder extends Seeder
{
    public const UNITS = [
        ['code' => 'pcs', 'name_en' => 'Piece', 'name_bn' => 'পিস', 'is_pair_unit' => false],
        ['code' => 'pair', 'name_en' => 'Pair', 'name_bn' => 'জোড়া', 'is_pair_unit' => true],
        ['code' => 'set', 'name_en' => 'Set', 'name_bn' => 'সেট', 'is_pair_unit' => false],
        ['code' => 'box', 'name_en' => 'Box', 'name_bn' => 'বক্স', 'is_pair_unit' => false],
    ];

    public const TAX_RATES = [
        ['code' => 'VAT0', 'name' => 'No VAT', 'rate_percent' => 0, 'is_default' => true],
        ['code' => 'VAT15', 'name' => 'VAT 15%', 'rate_percent' => 15, 'is_default' => false],
    ];

    public const CATEGORIES = [
        'Side Mirrors', 'Interior Rear-view Mirrors', 'Mirror Glass', 'Mirror Covers and Caps', 'Mirror Signal Lamps',
        'Mirror Motors and Actuators', 'Blind-spot Mirrors', 'Accessories (clips, adhesive, tools)',
    ];

    public const MAKES = [
        'Toyota', 'Honda', 'Nissan', 'Mitsubishi', 'Suzuki', 'Hyundai', 'Kia', 'Mazda', 'Subaru', 'Daihatsu', 'Ford', 'Isuzu', 'Tata',
        'Mahindra', 'BMW', 'Mercedes-Benz', 'Audi', 'Lexus', 'MG', 'Proton',
    ];

    public const MODELS = [
        'Toyota' => ['Axio', 'Allion', 'Premio', 'Corolla', 'Noah', 'Voxy', 'Hiace', 'Probox', 'Aqua', 'Prius', 'Harrier', 'Land Cruiser', 'Hilux', 'Vitz', 'Passo', 'CH-R'],
        'Honda' => ['Civic', 'Vezel', 'Fit', 'Grace', 'CR-V', 'Freed', 'Insight', 'City', 'Accord'],
        'Nissan' => ['X-Trail', 'Sylphy', 'Note', 'March', 'Juke', 'Serena'],
        'Mitsubishi' => ['Pajero', 'Outlander', 'Lancer', 'L200', 'Eclipse Cross'],
        'Suzuki' => ['Swift', 'Alto', 'Wagon R', 'Vitara', 'Every'],
        'Hyundai' => ['Tucson', 'Santa Fe', 'Elantra'],
        'Kia' => ['Sportage', 'Sorento', 'Picanto'],
    ];

    public function run(): void
    {
        Unit::withoutEvents(function (): void {
            foreach (self::UNITS as $u) {
                Unit::query()->firstOrCreate(['code' => $u['code']], $u);
            }
        });
        TaxRate::withoutEvents(function (): void {
            foreach (self::TAX_RATES as $t) {
                TaxRate::query()->firstOrCreate(['code' => $t['code']], $t);
            }
        });
        Category::withoutEvents(function (): void {
            foreach (self::CATEGORIES as $i => $name) {
                Category::query()->firstOrCreate(['slug' => Str::slug($name)], ['name_en' => $name, 'sort_order' => ($i + 1) * 10]);
            }
        });
        VehicleMake::withoutEvents(function (): void {
            VehicleModel::withoutEvents(function (): void {
                foreach (self::MAKES as $i => $name) {
                    $make = VehicleMake::query()->firstOrCreate(['name' => $name], ['sort_order' => ($i + 1) * 10]);
                    foreach (self::MODELS[$name] ?? [] as $model) {
                        VehicleModel::query()->firstOrCreate(['make_id' => $make->id, 'name' => $model, 'generation_code' => '']);
                    }
                }
            });
        });
    }
}
