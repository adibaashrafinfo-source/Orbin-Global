<?php

/**
 * E2E fixture for the POS spec, run through `php artisan tinker --execute="require '<this file>';"`.
 * Creates one published product with three variants (7x4 right, 8x5 right, 7x4 left) that fit a Toyota Axio 2012-2018, puts
 * ten of each on the showroom shelf at cost 600, and makes sure the company profile is complete so invoices can be made.
 * Prints one JSON line with the product name and the SKUs.
 */

use App\Models\CompanyProfile;
use App\Models\Product;
use App\Models\VehicleModel;
use App\Services\Catalog\VariantService;
use App\Services\Inventory\StockPostingService;
use Illuminate\Support\Facades\DB;

$stamp = strtoupper(base_convert((string) (int) (microtime(true) * 1000), 10, 36));
CompanyProfile::current()->forceFill([
    'legal_name' => 'Orbin Global', 'address_line1' => '12 Mirror Lane', 'city' => 'Dhaka', 'phones' => ['01711000000'], 'email' => 'hello@orbin.test',
    'invoice_terms' => 'Goods once sold are returnable within the window.',
])->save();

$name = "POS Mirror {$stamp}";
$productId = DB::table('products')->insertGetId([
    'code' => "POS-{$stamp}", 'name_en' => $name, 'mirror_type' => 'side_mirror', 'category_id' => DB::table('categories')->value('id'),
    'quality_grade_id' => DB::table('lookup_values')->where('type_id', DB::table('lookup_types')->where('key', 'quality_grade')->value('id'))->value('id'),
    'unit_id' => DB::table('units')->value('id'), 'is_active' => 1, 'warranty_months' => 6, 'created_at' => now(), 'updated_at' => now(),
]);
$product = Product::query()->findOrFail($productId);
$modelId = (int) VehicleModel::query()->where('name', 'Axio')->value('id');
$skus = [];
foreach ([['R', '7x4', 70, '1000.00'], ['R', '8x5', 80, '1500.00'], ['L', '7x4', 70, '1000.00']] as [$side, $label, $width, $price]) {
    $sku = "POS-{$stamp}-{$side}-{$label}";
    $v = app(VariantService::class)->create($product, [
        'sku' => $sku, 'position' => $side === 'R' ? 'right' : 'left', 'size_width_mm' => $width, 'size_height_mm' => 40, 'size_label' => $label, 'retail_price' => $price, 'min_price' => '900.00',
    ]);
    app(VariantService::class)->addFitmentRow($v->id, $modelId, 2012, 2018, null, null);
    DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $v->id, 'warehouse_id' => (int) DB::table('warehouses')->where('code', 'SHOW')->value('id'), 'type' => 'opening', 'qty' => '10', 'unit_cost' => '600']));
    $skus[$side.$label] = $sku;
}
echo 'POSSEED'.json_encode(['name' => $name, 'skus' => $skus]).PHP_EOL;
