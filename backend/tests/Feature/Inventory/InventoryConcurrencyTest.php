<?php

namespace Tests\Feature\Inventory;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Catalog\VariantService;
use App\Services\Inventory\IntegrityService;
use App\Services\Inventory\StockPostingService;
use App\Services\Inventory\StockTransferService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\SupplierService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

/**
 * AT-023 for stock: parallel requests for the last unit, the same receipt, the same transfer, and receipts racing sales.
 * Real forked processes; fixtures are committed and removed afterwards so the children can see them.
 */
class InventoryConcurrencyTest extends TestCase
{
    use RacesProcesses;

    private const CODE = 'ZZ-INVCONC';

    private int $showroom;

    private int $godown;

    /** No wrapping transaction: the racing processes must see committed fixtures and no test-held row locks. */
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl is required to prove stock integrity under concurrency.');
        }
        $this->cleanup();
        $this->showroom = (int) Warehouse::query()->where('kind', 'showroom')->value('id');
        $this->godown = (int) Warehouse::query()->where('kind', 'godown')->value('id');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $products = DB::table('products')->where('code', self::CODE)->pluck('id')->all();
        $variants = $products ? DB::table('product_variants')->whereIn('product_id', $products)->pluck('id')->all() : [];
        $suppliers = DB::table('suppliers')->where('code', 'like', 'ZZ-INV%')->pluck('id')->all();
        $grns = $suppliers ? DB::table('goods_receipts')->whereIn('supplier_id', $suppliers)->pluck('id')->all() : [];
        $returns = $suppliers ? DB::table('purchase_returns')->whereIn('supplier_id', $suppliers)->pluck('id')->all() : [];
        DB::table('purchase_return_items')->whereIn('return_id', $returns)->delete();
        DB::table('purchase_returns')->whereIn('id', $returns)->delete();
        DB::table('purchase_extra_costs')->whereIn('grn_id', $grns)->delete();
        DB::table('goods_receipt_items')->whereIn('grn_id', $grns)->delete();
        DB::table('goods_receipts')->whereIn('id', $grns)->delete();
        DB::table('party_ledger_entries')->where('party_type', 'supplier')->whereIn('party_id', $suppliers)->delete();
        DB::table('suppliers')->whereIn('id', $suppliers)->delete();
        $transfers = $variants ? DB::table('stock_transfer_items')->whereIn('variant_id', $variants)->pluck('transfer_id')->all() : [];
        DB::table('stock_transfer_items')->whereIn('transfer_id', $transfers)->delete();
        DB::table('stock_transfers')->whereIn('id', $transfers)->delete();
        DB::table('stock_movements')->whereIn('variant_id', $variants)->delete();
        DB::table('stock_levels')->whereIn('variant_id', $variants)->delete();
        DB::table('product_variants')->whereIn('id', $variants)->delete();
        DB::table('products')->whereIn('id', $products)->delete();
        DB::table('audit_logs')->whereIn('entity_table', ['product_variants', 'goods_receipts', 'stock_transfers', 'suppliers', 'purchase_returns'])->delete();
    }

    private function variant(): int
    {
        $pid = DB::table('products')->where('code', self::CODE)->value('id') ?? DB::table('products')->insertGetId([
            'code' => self::CODE, 'name_en' => 'Stock concurrency fixture', 'mirror_type' => 'side_mirror', 'category_id' => DB::table('categories')->value('id'),
            'quality_grade_id' => DB::table('lookup_values')->value('id'), 'unit_id' => DB::table('units')->value('id'), 'is_active' => 1, 'warranty_months' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) app(VariantService::class)->create(Product::query()->findOrFail($pid), ['sku' => 'ZZ-INVCONC-'.random_int(1000, 999999), 'position' => 'right', 'size_width_mm' => random_int(100, 900), 'retail_price' => '100.00'])->id;
    }

    private function mv(int $variant, string $type, string $qty, ?string $cost = null, ?int $warehouse = null): void
    {
        for ($attempt = 1; ; $attempt++) { // the same deadlock retry the services use
            try {
                DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $variant, 'warehouse_id' => $warehouse ?? $this->showroom, 'type' => $type, 'qty' => $qty, 'unit_cost' => $cost]));

                return;
            } catch (QueryException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function onHand(int $variant, ?int $warehouse = null): string
    {
        return (string) DB::table('stock_levels')->where(['variant_id' => $variant, 'warehouse_id' => $warehouse ?? $this->showroom])->value('qty_on_hand');
    }

    private function assertBooksHold(): void
    {
        $result = app(IntegrityService::class)->run();
        $failed = collect($result['checks'])->where('status', 'fail')->pluck('key')->all();
        $this->assertSame([], $failed, 'integrity check failed: '.json_encode($result['checks']));
    }

    public function test_six_requests_for_the_last_unit_only_one_gets_it(): void
    {
        $v = $this->variant();
        $this->mv($v, 'opening', '1', '100');
        $results = $this->race(6, function () use ($v): string {
            $this->mv($v, 'sale', '-1');

            return 'OK';
        });
        $this->assertSame(['INSUFFICIENT_STOCK', 'INSUFFICIENT_STOCK', 'INSUFFICIENT_STOCK', 'INSUFFICIENT_STOCK', 'INSUFFICIENT_STOCK', 'OK'], $results, json_encode($results));
        $this->assertSame('0.000', $this->onHand($v));
        $this->assertSame(2, DB::table('stock_movements')->where('variant_id', $v)->count(), 'the opening and exactly one sale');
        $this->assertBooksHold();
    }

    public function test_the_same_receipt_posted_five_times_at_once_counts_once(): void
    {
        $v = $this->variant();
        $supplier = app(SupplierService::class)->create(['name' => 'Race Supplier', 'code' => 'ZZ-INV1']);
        $grn = app(GoodsReceiptService::class)->create(['warehouse_id' => $this->showroom, 'supplier_id' => $supplier->id, 'received_date' => now('Asia/Dhaka')->toDateString(),
            'items' => [['variant_id' => $v, 'qty_received' => '10', 'unit_cost' => '100']], 'extra_costs' => [['cost_type' => 'freight', 'amount' => '100']]]);
        $results = $this->race(5, function () use ($grn): string {
            app(GoodsReceiptService::class)->post(GoodsReceipt::query()->findOrFail($grn->id));

            return 'OK';
        });
        $this->assertSame(['INVALID_STATE', 'INVALID_STATE', 'INVALID_STATE', 'INVALID_STATE', 'OK'], $results, json_encode($results));
        $this->assertSame('10.000', $this->onHand($v));
        $this->assertSame(1, DB::table('party_ledger_entries')->where(['party_type' => 'supplier', 'party_id' => $supplier->id])->count());
        $this->assertSame('110.0000', (string) DB::table('stock_levels')->where('variant_id', $v)->value('avg_cost'));
        $this->assertBooksHold();
    }

    public function test_the_same_transfer_sent_at_once_moves_the_goods_once(): void
    {
        $v = $this->variant();
        $this->mv($v, 'opening', '10', '100');
        $t = app(StockTransferService::class)->create(['from_warehouse_id' => $this->showroom, 'to_warehouse_id' => $this->godown, 'items' => [['variant_id' => $v, 'qty_sent' => '6']]]);
        $results = $this->race(4, function () use ($t): string {
            app(StockTransferService::class)->send(StockTransfer::query()->findOrFail($t->id));

            return 'OK';
        });
        $this->assertSame(['INVALID_STATE', 'INVALID_STATE', 'INVALID_STATE', 'OK'], $results, json_encode($results));
        $this->assertSame('4.000', $this->onHand($v));
        $this->assertBooksHold();
    }

    public function test_receipts_racing_sales_never_lose_or_invent_stock(): void
    {
        $v = $this->variant();
        $this->mv($v, 'opening', '20', '90');
        $supplier = app(SupplierService::class)->create(['name' => 'Race Supplier 2', 'code' => 'ZZ-INV2']);
        $grns = [];
        foreach ([100, 110, 120, 130] as $cost) {
            $grns[] = app(GoodsReceiptService::class)->create(['warehouse_id' => $this->showroom, 'supplier_id' => $supplier->id, 'received_date' => now('Asia/Dhaka')->toDateString(),
                'items' => [['variant_id' => $v, 'qty_received' => '10', 'unit_cost' => (string) $cost]]])->id;
        }
        $results = $this->race(8, function (int $i) use ($v, $grns): string {
            if ($i < 4) {
                app(GoodsReceiptService::class)->post(GoodsReceipt::query()->findOrFail($grns[$i]));
            } else {
                $this->mv($v, 'sale', '-5');
            }

            return 'OK';
        });
        $this->assertSame(array_fill(0, 8, 'OK'), $results, json_encode($results));
        // 20 + 4 × 10 − 4 × 5 = 40
        $this->assertSame('40.000', $this->onHand($v));
        $this->assertSame(9, DB::table('stock_movements')->where('variant_id', $v)->count());
        $this->assertBooksHold(); // includes the cost replay in movement order
    }

    public function test_two_returns_that_together_exceed_the_receipt_cannot_both_post(): void
    {
        $v = $this->variant();
        $supplier = app(SupplierService::class)->create(['name' => 'Race Supplier 3', 'code' => 'ZZ-INV3']);
        $grn = app(GoodsReceiptService::class)->post(app(GoodsReceiptService::class)->create(['warehouse_id' => $this->showroom, 'supplier_id' => $supplier->id, 'received_date' => now('Asia/Dhaka')->toDateString(),
            'items' => [['variant_id' => $v, 'qty_received' => '10', 'unit_cost' => '100']]]));
        $returns = [];
        foreach ([1, 2, 3] as $_) { // each 6 of the 10 received: only one of the three can fit
            $returns[] = app(PurchaseReturnService::class)->create(['supplier_id' => $supplier->id, 'warehouse_id' => $this->showroom, 'grn_id' => $grn->id, 'return_date' => now('Asia/Dhaka')->toDateString(),
                'items' => [['variant_id' => $v, 'qty' => '6', 'unit_cost' => '100']]])->id;
        }
        $results = $this->race(3, function (int $i) use ($returns): string {
            app(PurchaseReturnService::class)->post(PurchaseReturn::query()->findOrFail($returns[$i]));

            return 'OK';
        });
        $this->assertSame(['OK', 'RETURN_EXCEEDS_RECEIPT', 'RETURN_EXCEEDS_RECEIPT'], $results, json_encode($results));
        $this->assertSame('4.000', $this->onHand($v));
        $this->assertSame('-400.00', (string) DB::table('party_ledger_entries')->where(['party_type' => 'supplier', 'party_id' => $supplier->id])->selectRaw('SUM(debit) - SUM(credit) as b')->value('b'), 'owed 1,000 less one return of 600');
        $this->assertBooksHold();
    }
}
