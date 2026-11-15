<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Services\Catalog\VariantService;
use App\Services\Inventory\StockPostingService;
use App\Services\Sales\CustomerService;
use App\Services\Sales\SalePricing;
use App\Services\Sales\SaleService;
use App\Services\Sales\ShiftService;
use App\Services\SettingsService;
use App\Support\Dec;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample business data for training and for checking the screens (not for production). Everything goes through the same services
 * the app uses, so stock, ledgers and invoices are real: three mirror products with stock, three customers (one on credit), a cash
 * shift, and a few sales over the last days. Idempotent: it does nothing when the DEMO- products already exist.
 */
class DemoDataSeeder extends Seeder
{
    public const CODE_PREFIX = 'DEMO-';

    public function run(): void
    {
        if (Product::query()->where('code', 'like', self::CODE_PREFIX.'%')->exists()) {
            $this->command?->info('Demo data already present; nothing added.');

            return;
        }

        $warehouse = Warehouse::query()->where('is_default', true)->orWhere('code', 'SHOW')->orderByDesc('is_default')->firstOrFail();
        $cashier = User::query()->where('role', 'cashier')->where('is_active', true)->orderBy('id')->firstOrFail();
        $cash = FinancialAccount::query()->where('account_type', 'cash')->where('is_active', true)->orderBy('id')->firstOrFail();

        // All or nothing: a half-made demo set would make the guard above think the job was done.
        DB::transaction(function () use ($cashier, $cash, $warehouse): void {
            $products = $this->products($warehouse);
            $customers = $this->customers();
            if (! app(ShiftService::class)->current($cashier)) {
                app(ShiftService::class)->open($cashier, $cash->id, '1000');
            }
            $this->sales($cashier, $warehouse, $products, $customers);
        });
    }

    /** @return array<string, array{variant_id: int, price: string}> */
    private function products(Warehouse $warehouse): array
    {
        $axio = VehicleModel::query()->where('name', 'Axio')->value('id');
        $out = [];
        $defs = [
            ['key' => 'side', 'name' => 'Demo side mirror', 'type' => 'side_mirror', 'position' => 'right', 'label' => '7x4', 'width' => 70, 'price' => '1000.00', 'fit' => $axio],
            ['key' => 'interior', 'name' => 'Demo interior mirror', 'type' => 'interior_mirror', 'position' => 'interior', 'label' => '30cm', 'width' => 300, 'price' => '750.00', 'fit' => null],
            ['key' => 'glass', 'name' => 'Demo mirror glass', 'type' => 'mirror_glass', 'position' => 'left', 'label' => '8x5', 'width' => 80, 'price' => '450.00', 'fit' => $axio],
        ];
        foreach ($defs as $d) {
            $code = self::CODE_PREFIX.strtoupper($d['key']);
            $productId = DB::table('products')->insertGetId([
                'code' => $code, 'name_en' => $d['name'], 'mirror_type' => $d['type'], 'category_id' => DB::table('categories')->value('id'),
                'quality_grade_id' => DB::table('lookup_values')->where('type_id', DB::table('lookup_types')->where('key', 'quality_grade')->value('id'))->value('id'),
                'unit_id' => DB::table('units')->value('id'), 'is_active' => 1, 'warranty_months' => 6, 'tags' => '[]', 'custom' => '{}', 'search_text' => strtolower($d['name'].' '.$code),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $product = Product::query()->findOrFail($productId);
            $sku = $code.'-'.strtoupper($d['label']);
            $variant = app(VariantService::class)->create($product, [
                'sku' => $sku, 'position' => $d['position'], 'size_width_mm' => $d['width'], 'size_label' => $d['label'], 'retail_price' => $d['price'], 'min_price' => '0.00',
            ]);
            if ($d['fit'] !== null) {
                app(VariantService::class)->addFitmentRow($variant->id, (int) $d['fit'], 2012, 2018, null, null);
            }
            DB::transaction(fn () => app(StockPostingService::class)->post([
                'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'type' => 'opening', 'qty' => '12', 'unit_cost' => '600',
            ]));
            $out[$d['key']] = ['variant_id' => $variant->id, 'price' => $d['price']];
        }

        return $out;
    }

    /** @return array<string, Customer> */
    private function customers(): array
    {
        $service = app(CustomerService::class);
        $toyota = VehicleMake::query()->where('name', 'Toyota')->value('id');
        $axio = VehicleModel::query()->where('name', 'Axio')->value('id');
        $defs = [
            'walkin' => ['name' => 'Demo Garage Rahim', 'phones' => ['01711222001'], 'credit_limit' => '5000.00', 'vehicles' => [['reg_no' => 'DHAKA-METRO-LA-11', 'make_id' => $toyota, 'model_id' => $axio, 'year' => 2015, 'color' => 'White']]],
            'credit' => ['name' => 'Demo Motors Karim', 'phones' => ['01711222002'], 'credit_limit' => '10000.00', 'vehicles' => []],
            'dealer' => ['name' => 'Demo Dealer Salma', 'phones' => ['01711222003'], 'price_tier' => 'dealer', 'credit_limit' => '0.00', 'vehicles' => []],
        ];
        $out = [];
        foreach ($defs as $key => $d) {
            $existing = Customer::query()->where('phone_primary', $d['phones'][0])->first();
            $customer = $existing ?? $service->create(collect($d)->except('vehicles')->put('price_tier', $d['price_tier'] ?? 'retail')->all());
            foreach ($d['vehicles'] as $car) {
                $service->saveVehicle($customer, $car);
            }
            $out[$key] = $customer;
        }

        return $out;
    }

    /** @param  array<string, array{variant_id: int, price: string}>  $products */
    private function sales(User $cashier, Warehouse $warehouse, array $products, array $customers): void
    {
        $walkIn = Customer::query()->where('is_walk_in', true)->firstOrFail();
        $plans = [
            ['customer' => $walkIn, 'lines' => ['side' => 2], 'pay' => [['method' => 'cash', 'share' => 'full']]],
            ['customer' => $customers['credit'], 'lines' => ['side' => 1, 'glass' => 1], 'pay' => [['method' => 'cash', 'share' => 'part', 'amount' => '500.00']]],
            ['customer' => $customers['dealer'], 'lines' => ['interior' => 4], 'pay' => [['method' => 'bkash', 'share' => 'full', 'sender' => '01711222003', 'trx' => 'DEMO-TRX-0001']]],
            ['customer' => $walkIn, 'lines' => ['glass' => 3, 'interior' => 1], 'pay' => [['method' => 'cash', 'share' => 'full']]],
        ];
        $settings = app(SettingsService::class);
        foreach ($plans as $n => $plan) {
            $lines = [];
            $calcLines = [];
            foreach ($plan['lines'] as $key => $qty) {
                $lines[] = ['variant_id' => $products[$key]['variant_id'], 'qty' => (string) $qty, 'unit_price' => $products[$key]['price'], 'discount_type' => 'none', 'discount_value' => '0'];
                $calcLines[] = ['qty' => (string) $qty, 'unit_price' => $products[$key]['price'], 'discount_type' => 'none', 'discount_value' => '0', 'tax_rate' => '0'];
            }
            $calc = SalePricing::compute($calcLines, 'none', '0', '0', (string) $settings->get('vat_mode'), (string) $settings->get('rounding_mode'));
            $grand = Dec::fmt($calc['grand_total'], Dec::MONEY);
            $payments = [];
            foreach ($plan['pay'] as $p) {
                $amount = $p['share'] === 'full' ? $grand : $p['amount'];
                $payments[] = array_filter(['method' => $p['method'], 'amount' => $amount, 'sender_number' => $p['sender'] ?? null, 'trx_id' => $p['trx'] ?? null], fn ($v) => $v !== null);
            }
            $payload = [
                'idempotency_key' => 'demo-sale-'.$n, 'lines' => $lines, 'customer_id' => $plan['customer']->id, 'warehouse_id' => $warehouse->id,
                'sale_channel' => $n === 2 ? 'wholesale' : 'counter', 'cart_discount' => ['type' => 'none', 'value' => '0'], 'delivery_charge' => '0',
                'client_expected_grand_total' => $grand, 'payments' => $payments, 'note' => 'Demo sale',
            ];
            app(SaleService::class)->complete($cashier, $payload);
        }
    }
}
