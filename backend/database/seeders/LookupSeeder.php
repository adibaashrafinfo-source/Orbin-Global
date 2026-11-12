<?php

namespace Database\Seeders;

use App\Models\LookupType;
use App\Models\LookupValue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** spec lookups.systemTypes — system types cannot be deleted, only extended. */
class LookupSeeder extends Seeder
{
    public const SYSTEM_TYPES = [
        'origin_country' => ['Country of origin', ['Japan', 'Taiwan', 'China', 'Thailand', 'India', 'South Korea', 'Malaysia', 'Bangladesh']],
        'quality_grade' => ['Quality grade', ['Genuine OEM', 'OEM equivalent', 'Aftermarket premium', 'Aftermarket standard', 'Used / reconditioned']],
        'glass_type' => ['Glass type', ['Flat', 'Convex', 'Aspheric (multi-radius)', 'Anti-glare', 'Wide-angle']],
        'glass_tint' => ['Glass tint / coating', ['Clear chrome', 'Blue tint', 'Anti-glare blue', 'Hydrophobic']],
        'color_finish' => ['Color / finish', ['Black', 'Chrome', 'Silver', 'Body color (painted)', 'Carbon look', 'Unpainted / primed']],
        'customer_type' => ['Customer type', ['Retail', 'Wholesale', 'Garage / workshop', 'Dealer', 'Corporate']],
        'return_reason' => ['Sales return reason', ['Wrong fitment', 'Wrong size', 'Damaged on delivery', 'Defective glass', 'Customer changed mind', 'Other']],
        'adjustment_reason' => ['Stock adjustment reason', ['Breakage', 'Damaged in transit', 'Lost / theft', 'Found', 'Recount', 'Sample / demo', 'Other']],
        'body_type' => ['Vehicle body type', ['Sedan', 'Hatchback', 'SUV', 'MPV / Microbus', 'Pickup', 'Truck', 'Bus', 'Coupe', 'Station wagon']],
    ];

    public function run(): void
    {
        LookupType::withoutEvents(function (): void {
            LookupValue::withoutEvents(function (): void {
                foreach (self::SYSTEM_TYPES as $key => [$name, $values]) {
                    $type = LookupType::query()->firstOrCreate(['key' => $key], ['name_en' => $name, 'is_system' => true]);
                    foreach ($values as $i => $label) {
                        LookupValue::query()->firstOrCreate(
                            ['type_id' => $type->id, 'code' => Str::slug($label, '_')],
                            ['label_en' => $label, 'sort_order' => ($i + 1) * 10],
                        );
                    }
                }
            });
        });
    }
}
