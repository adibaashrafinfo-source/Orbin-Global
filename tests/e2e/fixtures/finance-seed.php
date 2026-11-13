<?php

/**
 * E2E fixture for the finance spec, run through `php artisan tinker --execute="require '<this file>';"`.
 * Makes the company profile complete, creates a customer with three credit invoices of ৳1,000 each, a supplier with one posted
 * goods receipt of ৳5,000, Prints one JSON line.
 */

use App\Models\CompanyProfile;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\VariantService;
use App\Services\Inventory\StockPostingService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\SupplierService;
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
    'code' => "FIN-{$stamp}", 'name_en' => "Finance Mirror {$stamp}", 'mirror_type' => 'side_mirror', 'category_id' => DB::table('categories')->value('id'),
    'quality_grade_id' => DB::table('lookup_values')->where('type_id', DB::table('lookup_types')->where('key', 'quality_grade')->value('id'))->value('id'),
    'unit_id' => DB::table('units')->value('id'), 'is_active' => 1, 'warranty_months' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
$variant = app(VariantService::class)->create(Product::query()->findOrFail($productId), ['sku' => "FIN-{$stamp}-A", 'position' => 'right', 'size_width_mm' => 90, 'size_height_mm' => 40, 'size_label' => '9x4', 'retail_price' => '1000.00']);
DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $variant->id, 'warehouse_id' => $showroom, 'type' => 'opening', 'qty' => '50', 'unit_cost' => '600']));

$customer = app(CustomerService::class)->create(['name' => "Fin Garage {$stamp}", 'phones' => ['017'.random_int(10000000, 99999999)], 'price_tier' => 'retail', 'credit_limit' => '0']);
$settings = app(SettingsService::class);
$settings->update(['require_open_shift' => false]);
$invoices = [];
foreach ([1, 2, 3] as $i) {
    $sale = app(SaleService::class)->complete($admin, [
        'idempotency_key' => bin2hex(random_bytes(12)), 'lines' => [['variant_id' => $variant->id, 'qty' => '1', 'unit_price' => '1000.00']], 'customer_id' => $customer->id, 'warehouse_id' => $showroom,
        'client_expected_grand_total' => '1000.00', 'payments' => [],
    ]);
    $invoices[] = $sale->invoice_no;
}
$settings->update(['require_open_shift' => true]);

$supplier = app(SupplierService::class)->create(['name' => "Fin Supplier {$stamp}", 'supplier_type' => 'import']);
$grn = app(GoodsReceiptService::class);
$receipt = $grn->post($grn->create([
    'warehouse_id' => $showroom, 'supplier_id' => $supplier->id, 'received_date' => now('Asia/Dhaka')->toDateString(), 'allocation_basis' => 'value',
    'items' => [['variant_id' => $variant->id, 'qty_received' => '50', 'qty_damaged' => '0', 'unit_cost' => '100']], 'extra_costs' => [],
]));

echo 'FINSEED'.json_encode(['customer' => $customer->name, 'customerCode' => $customer->code, 'invoices' => $invoices, 'supplier' => $supplier->name, 'grn' => $receipt->grn_no, 'stamp' => $stamp]).PHP_EOL;
