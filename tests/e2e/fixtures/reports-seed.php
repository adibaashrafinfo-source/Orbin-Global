<?php

/**
 * E2E fixture for the reports spec, run through `php artisan tinker --execute="require '<this file>';"`.
 * Makes the company profile complete and sells three mirrors today: one for cash, one half cash and half bKash, one on credit with a
 * discount. Prints one JSON line with the invoice numbers.
 */

use App\Models\CompanyProfile;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\VariantService;
use App\Services\Inventory\StockPostingService;
use App\Services\Sales\CustomerService;
use App\Services\Sales\SaleService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$stamp = strtoupper(base_convert((string) (int) (microtime(true) * 1000), 10, 36));
CompanyProfile::current()->forceFill([
    'legal_name' => 'Orbin Global', 'address_line1' => '12 Mirror Lane', 'city' => 'Dhaka', 'phones' => ['01711000000'], 'email' => 'hello@orbin.test',
    'invoice_terms' => 'Goods once sold are returnable within the window.',
])->save();
$admin = User::query()->where('email', 'admin@orbin.local')->firstOrFail();
Auth::setUser($admin);
$showroom = (int) DB::table('warehouses')->where('code', 'SHOW')->value('id');

$productId = DB::table('products')->insertGetId([
    'code' => "REP-{$stamp}", 'name_en' => "Report Mirror {$stamp}", 'mirror_type' => 'side_mirror', 'category_id' => DB::table('categories')->value('id'),
    'quality_grade_id' => DB::table('lookup_values')->where('type_id', DB::table('lookup_types')->where('key', 'quality_grade')->value('id'))->value('id'),
    'unit_id' => DB::table('units')->value('id'), 'is_active' => 1, 'warranty_months' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
$variant = app(VariantService::class)->create(Product::query()->findOrFail($productId), ['sku' => "REP-{$stamp}-A", 'position' => 'right', 'size_width_mm' => 95, 'size_height_mm' => 40, 'size_label' => '95x40', 'retail_price' => '1000.00', 'reorder_level' => '100']);
DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $variant->id, 'warehouse_id' => $showroom, 'type' => 'opening', 'qty' => '30', 'unit_cost' => '600']));

$customer = app(CustomerService::class)->create(['name' => "Report Garage {$stamp}", 'phones' => ['017'.random_int(10000000, 99999999)], 'price_tier' => 'retail', 'credit_limit' => '0']);
$settings = app(SettingsService::class);
$settings->update(['require_open_shift' => false]);
$sell = function (array $payments, string $total, array $over = []) use ($admin, $variant, $showroom, $customer): string {
    $body = ['idempotency_key' => bin2hex(random_bytes(12)), 'lines' => [['variant_id' => $variant->id, 'qty' => '1', 'unit_price' => '1000.00']], 'customer_id' => $customer->id, 'warehouse_id' => $showroom, 'client_expected_grand_total' => $total, 'payments' => $payments];
    $body = array_merge($body, $over);

    return app(SaleService::class)->complete($admin, $body)->invoice_no;
};
$invoices = [
    $sell([['method' => 'cash', 'amount' => '1000.00']], '1000.00'),
    $sell([['method' => 'cash', 'amount' => '500.00'], ['method' => 'bkash', 'amount' => '500.00', 'sender_number' => '01712345678', 'trx_id' => "REP{$stamp}"]], '1000.00'),
];
$discounted = ['lines' => [['variant_id' => $variant->id, 'qty' => '1', 'unit_price' => '1000.00', 'discount_type' => 'amount', 'discount_value' => '100.00']], 'approvals' => ['manager_pin' => '2345', 'reason' => 'e2e']];
$invoices[] = $sell([], '900.00', $discounted);
$settings->update(['require_open_shift' => true]);

echo 'REPSEED'.json_encode(['invoices' => $invoices, 'stamp' => $stamp, 'sku' => "REP-{$stamp}-A"]).PHP_EOL;
