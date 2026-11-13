<?php

namespace Tests\Feature\Sales;

use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\VariantService;
use App\Services\Inventory\IntegrityService;
use App\Services\Inventory\StockPostingService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Services\Sales\ShiftService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

/**
 * Phase 3 gate: "two concurrent sales of the last unit: exactly one succeeds", plus a duplicate TrxID, a double click, gap-free invoice
 * numbers, a second open shift, and a void racing a return. Real forked processes with their own database connections; fixtures are
 * committed (the children must see them) and everything is removed afterwards, number sequences included.
 */
class SalesConcurrencyTest extends TestCase
{
    use RacesProcesses;

    private const CODE = 'ZZ-SALECONC';

    private int $showroom;

    private int $cashierId;

    private int $managerId;

    private int $walkIn;

    /** @var array<string, int> the sequence counters before the test, restored afterwards so later test classes see the seeded state */
    private array $sequences = [];

    /** @var array<string, mixed> */
    private array $companyBefore = [];

    /** No wrapping transaction: the racing processes must see committed fixtures and no test-held row locks. */
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl is required to prove the sale guards under concurrency.');
        }
        $this->cleanup();
        $this->showroom = (int) Warehouse::query()->where('kind', 'showroom')->value('id');
        $this->cashierId = $this->userWithRole('cashier')->id;
        $this->managerId = $this->userWithRole('manager')->id;
        $this->walkIn = (int) Customer::query()->where('is_walk_in', true)->value('id');
        $this->sequences = DB::table('number_sequences')->whereIn('key', ['invoice', 'shift', 'sale_return', 'customer'])->pluck('next_value', 'id')->all();
        $this->companyBefore = CompanyProfile::current()->only(['legal_name', 'address_line1', 'phones']);
        CompanyProfile::current()->forceFill(['legal_name' => 'Orbin Global', 'address_line1' => '12 Mirror Lane', 'phones' => ['01711000000']])->save();
        app(SettingsService::class)->update(['require_open_shift' => false]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        // A sequence row is created on first use, so rows that did not exist before are removed and the rest are put back.
        DB::table('number_sequences')->whereIn('key', ['invoice', 'shift', 'sale_return', 'customer'])->whereNotIn('id', array_keys($this->sequences))->delete();
        foreach ($this->sequences as $id => $next) {
            DB::table('number_sequences')->where('id', $id)->update(['next_value' => $next]);
        }
        if ($this->companyBefore) {
            CompanyProfile::current()->forceFill($this->companyBefore)->save();
        }
        DB::table('app_settings')->where('key', 'require_open_shift')->delete();
        app(SettingsService::class)->forgetCache();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $products = DB::table('products')->where('code', self::CODE)->pluck('id')->all();
        $variants = $products ? DB::table('product_variants')->whereIn('product_id', $products)->pluck('id')->all() : [];
        $sales = DB::table('sale_items')->whereIn('variant_id', $variants)->pluck('sale_id')->all();
        $returns = $sales ? DB::table('sale_returns')->whereIn('sale_id', $sales)->pluck('id')->all() : [];
        DB::table('sale_return_items')->whereIn('return_id', $returns)->delete();
        DB::table('sale_returns')->whereIn('id', $returns)->delete();
        DB::table('account_transactions')->whereIn('ref_type', ['sale_payment', 'sale_refund', 'adjustment', 'shift_cash_movement'])->delete();
        DB::table('party_ledger_entries')->where('party_type', 'customer')->delete();
        DB::table('sale_payments')->whereIn('sale_id', $sales)->delete();
        DB::table('sale_items')->whereIn('sale_id', $sales)->delete();
        DB::table('sales')->whereIn('id', $sales)->delete();
        DB::table('cash_shifts')->delete();
        DB::table('stock_movements')->whereIn('variant_id', $variants)->delete();
        DB::table('stock_levels')->whereIn('variant_id', $variants)->delete();
        DB::table('product_variants')->whereIn('id', $variants)->delete();
        DB::table('products')->whereIn('id', $products)->delete();
        DB::table('customers')->where('is_walk_in', false)->delete();
        DB::table('audit_logs')->whereIn('entity_table', ['sales', 'sale_returns', 'cash_shifts', 'product_variants', 'customers'])->delete();
        DB::table('notifications')->where('type', 'like', '%stock%')->delete();
    }

    private function stocked(string $qty): int
    {
        $pid = DB::table('products')->where('code', self::CODE)->value('id') ?? DB::table('products')->insertGetId([
            'code' => self::CODE, 'name_en' => 'Sale concurrency fixture', 'mirror_type' => 'side_mirror', 'category_id' => DB::table('categories')->value('id'),
            'quality_grade_id' => DB::table('lookup_values')->value('id'), 'unit_id' => DB::table('units')->value('id'), 'is_active' => 1, 'warranty_months' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $v = (int) app(VariantService::class)->create(Product::query()->findOrFail($pid), ['sku' => 'ZZ-SALECONC-'.random_int(1000, 999999), 'position' => 'right', 'size_width_mm' => random_int(100, 900), 'retail_price' => '1000.00'])->id;
        DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $v, 'warehouse_id' => $this->showroom, 'type' => 'opening', 'qty' => $qty, 'unit_cost' => '600']));

        return $v;
    }

    /** @param  array<string, mixed>  $over */
    private function payload(int $variant, array $over = []): array
    {
        return array_merge([
            'idempotency_key' => bin2hex(random_bytes(12)), 'lines' => [['variant_id' => $variant, 'qty' => '1', 'unit_price' => '1000.00']], 'customer_id' => $this->walkIn, 'warehouse_id' => $this->showroom,
            'client_expected_grand_total' => '1000.00', 'payments' => [['method' => 'cash', 'amount' => '1000.00']],
        ], $over);
    }

    private function sell(int $variant, array $over = []): string
    {
        $sale = app(SaleService::class)->complete(User::query()->findOrFail($this->cashierId), $this->payload($variant, $over));

        return 'OK:'.$sale->id;
    }

    private function assertBooksHold(): void
    {
        $failed = collect(app(IntegrityService::class)->run()['checks'])->where('status', 'fail')->pluck('key')->all();
        $this->assertSame([], $failed, 'integrity check failed');
    }

    /** @param  array<int, string>  $results */
    private function oks(array $results): int
    {
        return count(array_filter($results, fn ($r) => str_starts_with($r, 'OK:')));
    }

    public function test_eight_sales_of_the_last_unit_exactly_one_succeeds(): void
    {
        $v = $this->stocked('1');
        $results = $this->race(8, fn () => $this->sell($v));
        $this->assertSame(1, $this->oks($results), json_encode($results));
        $this->assertSame(array_fill(0, 7, 'INSUFFICIENT_STOCK'), array_values(array_filter($results, fn ($r) => ! str_starts_with($r, 'OK:'))), json_encode($results));
        $this->assertSame('0.000', (string) DB::table('stock_levels')->where('variant_id', $v)->value('qty_on_hand'));
        $this->assertSame(1, Sale::query()->count());
        $this->assertStringEndsWith('-000001', (string) Sale::query()->value('invoice_no'), 'the losers did not use up invoice numbers');
        $this->assertBooksHold();
    }

    public function test_the_same_trxid_in_parallel_sales_is_accepted_once(): void
    {
        $v = $this->stocked('20');
        $over = ['payments' => [['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678', 'trx_id' => 'RACE-TRX']]];
        $results = $this->race(6, fn () => $this->sell($v, $over));
        $this->assertSame(1, $this->oks($results), json_encode($results));
        $this->assertSame(array_fill(0, 5, 'DUPLICATE_TRXID'), array_values(array_filter($results, fn ($r) => ! str_starts_with($r, 'OK:'))), json_encode($results));
        $this->assertSame(1, DB::table('sale_payments')->where('trx_id', 'RACE-TRX')->count());
        $this->assertSame('19.000', (string) DB::table('stock_levels')->where('variant_id', $v)->value('qty_on_hand'), 'refused sales gave nothing away');
        $this->assertBooksHold();
    }

    public function test_a_double_click_with_the_same_idempotency_key_is_one_sale(): void
    {
        $v = $this->stocked('10');
        $key = 'double-click-'.bin2hex(random_bytes(4));
        $results = $this->race(6, fn () => $this->sell($v, ['idempotency_key' => $key]));
        $this->assertCount(1, array_unique($results), json_encode($results));
        $this->assertStringStartsWith('OK:', $results[0]);
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('9.000', (string) DB::table('stock_levels')->where('variant_id', $v)->value('qty_on_hand'));
        $this->assertSame(1, DB::table('sale_payments')->count());
        $this->assertBooksHold();
    }

    public function test_invoice_numbers_stay_gap_free_when_some_parallel_sales_fail(): void
    {
        $v = $this->stocked('6');
        $results = $this->race(10, fn () => $this->sell($v));
        $this->assertSame(6, $this->oks($results), json_encode($results));
        $numbers = Sale::query()->orderBy('id')->pluck('invoice_no')->map(fn ($n) => (int) substr((string) $n, -6))->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4, 5, 6], $numbers, 'no gaps and no duplicates (BR-070)');
        $this->assertSame('0.000', (string) DB::table('stock_levels')->where('variant_id', $v)->value('qty_on_hand'));
        $this->assertBooksHold();
    }

    public function test_a_cashier_cannot_open_two_shifts_at_once(): void
    {
        $cash = (int) DB::table('financial_accounts')->where('account_type', 'cash')->value('id');
        $results = $this->race(5, function () use ($cash): string {
            app(ShiftService::class)->open(User::query()->findOrFail($this->cashierId), $cash, '100');

            return 'OK';
        });
        $this->assertSame(['OK', 'SHIFT_ALREADY_OPEN', 'SHIFT_ALREADY_OPEN', 'SHIFT_ALREADY_OPEN', 'SHIFT_ALREADY_OPEN'], $results);
        $this->assertSame(1, DB::table('cash_shifts')->where('status', 'open')->count());
    }

    public function test_a_void_and_a_return_racing_on_one_invoice_cannot_both_win(): void
    {
        $v = $this->stocked('10');
        $sale = app(SaleService::class)->complete(User::query()->findOrFail($this->cashierId), $this->payload($v, ['lines' => [['variant_id' => $v, 'qty' => '2', 'unit_price' => '1000.00']], 'client_expected_grand_total' => '2000.00', 'payments' => [['method' => 'cash', 'amount' => '2000.00']]]));
        $line = $sale->items()->first()->id;
        $results = $this->race(4, function (int $i) use ($sale, $line): string {
            $manager = User::query()->findOrFail($this->managerId);
            if ($i % 2 === 0) {
                app(SaleService::class)->void($manager, Sale::query()->findOrFail($sale->id), 'race');

                return 'VOID_OK';
            }
            app(SaleReturnService::class)->create($manager, Sale::query()->findOrFail($sale->id), ['items' => [['sale_item_id' => $line, 'qty' => '2', 'condition' => 'resellable']], 'refund_mode' => 'cash']);

            return 'RETURN_OK';
        });
        $wins = array_values(array_filter($results, fn ($r) => in_array($r, ['VOID_OK', 'RETURN_OK'], true)));
        $this->assertCount(1, $wins, 'exactly one of void and return may succeed: '.json_encode($results));
        $this->assertSame('10.000', (string) DB::table('stock_levels')->where('variant_id', $v)->value('qty_on_hand'), 'stock came back exactly once');
        $this->assertBooksHold();
    }
}
