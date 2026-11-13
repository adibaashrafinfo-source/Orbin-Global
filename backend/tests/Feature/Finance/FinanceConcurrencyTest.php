<?php

namespace Tests\Feature\Finance;

use App\Models\CompanyProfile;
use App\Models\PartyPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\VariantService;
use App\Services\Finance\FundTransferService;
use App\Services\Finance\PartyAdjustmentService;
use App\Services\Finance\PartyPaymentService;
use App\Services\Inventory\IntegrityService;
use App\Services\Inventory\StockPostingService;
use App\Services\Sales\CustomerService;
use App\Services\Sales\SaleService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

/**
 * Phase 4 concurrency guards with real forked processes and their own database connections: two receipts cannot allocate one invoice
 * twice, a TrxID is accepted once, an account cannot be spent twice, a receipt and a write-off cannot both settle the same due, and
 * payment numbers stay gap-free. Fixtures are committed (the children must see them) and removed afterwards.
 */
class FinanceConcurrencyTest extends TestCase
{
    use RacesProcesses;

    private const CODE = 'ZZ-FINCONC';

    private int $showroom;

    private int $accountantId;

    private int $cashierId;

    private int $customerId;

    /** @var array<int, int> sequence id → next value, restored afterwards */
    private array $sequences = [];

    /** @var array<string, mixed> */
    private array $companyBefore = [];

    /** No wrapping transaction: the racing processes must see committed fixtures and no test-held row locks. */
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl is required to prove the finance guards under concurrency.');
        }
        $this->cleanup();
        $this->showroom = (int) Warehouse::query()->where('kind', 'showroom')->value('id');
        $this->accountantId = $this->userWithRole('accountant')->id;
        $this->cashierId = $this->userWithRole('cashier')->id;
        $this->sequences = DB::table('number_sequences')->whereIn('key', ['invoice', 'party_payment', 'fund_transfer', 'expense', 'customer'])->pluck('next_value', 'id')->all();
        $this->companyBefore = CompanyProfile::current()->only(['legal_name', 'address_line1', 'phones']);
        CompanyProfile::current()->forceFill(['legal_name' => 'Orbin Global', 'address_line1' => '12 Mirror Lane', 'phones' => ['01711000000']])->save();
        app(SettingsService::class)->update(['require_open_shift' => false]);
        $this->customerId = app(CustomerService::class)->create(['name' => 'Race Garage', 'phones' => ['01799000001'], 'price_tier' => 'retail', 'credit_limit' => '0'])->id;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        DB::table('number_sequences')->whereIn('key', ['invoice', 'party_payment', 'fund_transfer', 'expense', 'customer'])->whereNotIn('id', array_keys($this->sequences))->delete();
        foreach ($this->sequences as $id => $next) {
            DB::table('number_sequences')->where('id', $id)->update(['next_value' => $next]);
        }
        if ($this->companyBefore) {
            CompanyProfile::current()->forceFill($this->companyBefore)->save();
        }
        DB::table('app_settings')->where('key', 'require_open_shift')->delete();
        DB::table('financial_accounts')->update(['opening_balance' => '0.00']);
        app(SettingsService::class)->forgetCache();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $products = DB::table('products')->where('code', self::CODE)->pluck('id')->all();
        $variants = $products ? DB::table('product_variants')->whereIn('product_id', $products)->pluck('id')->all() : [];
        $sales = DB::table('sale_items')->whereIn('variant_id', $variants)->pluck('sale_id')->all();
        DB::table('party_payment_allocations')->delete();
        DB::table('party_payments')->delete();
        DB::table('fund_transfers')->delete();
        DB::table('expenses')->delete();
        DB::table('account_transactions')->delete();
        DB::table('party_ledger_entries')->delete();
        DB::table('sale_payments')->whereIn('sale_id', $sales)->delete();
        DB::table('sale_items')->whereIn('sale_id', $sales)->delete();
        DB::table('sales')->whereIn('id', $sales)->delete();
        DB::table('stock_movements')->whereIn('variant_id', $variants)->delete();
        DB::table('stock_levels')->whereIn('variant_id', $variants)->delete();
        DB::table('product_variants')->whereIn('id', $variants)->delete();
        DB::table('products')->whereIn('id', $products)->delete();
        DB::table('customers')->where('is_walk_in', false)->delete();
        DB::table('audit_logs')->whereIn('entity_table', ['sales', 'party_payments', 'fund_transfers', 'expenses', 'customers', 'product_variants', 'financial_accounts'])->delete();
        DB::table('notifications')->where('type', 'like', '%stock%')->delete();
    }

    private function stocked(): int
    {
        $pid = DB::table('products')->where('code', self::CODE)->value('id') ?? DB::table('products')->insertGetId([
            'code' => self::CODE, 'name_en' => 'Finance concurrency fixture', 'mirror_type' => 'side_mirror', 'category_id' => DB::table('categories')->value('id'),
            'quality_grade_id' => DB::table('lookup_values')->value('id'), 'unit_id' => DB::table('units')->value('id'), 'is_active' => 1, 'warranty_months' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $v = (int) app(VariantService::class)->create(Product::query()->findOrFail($pid), ['sku' => 'ZZ-FINCONC-'.random_int(1000, 999999), 'position' => 'right', 'size_width_mm' => random_int(100, 900), 'retail_price' => '1000.00'])->id;
        DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $v, 'warehouse_id' => $this->showroom, 'type' => 'opening', 'qty' => '50', 'unit_cost' => '600']));

        return $v;
    }

    /** A credit invoice of one mirror (1,000), nothing paid. */
    private function dueInvoice(): Sale
    {
        $v = $this->stocked();

        return app(SaleService::class)->complete(User::query()->findOrFail($this->cashierId), [
            'idempotency_key' => bin2hex(random_bytes(12)), 'lines' => [['variant_id' => $v, 'qty' => '1', 'unit_price' => '1000.00']], 'customer_id' => $this->customerId, 'warehouse_id' => $this->showroom,
            'client_expected_grand_total' => '1000.00', 'payments' => [],
        ]);
    }

    /** @param  array<string, mixed>  $over */
    private function receive(string $amount, array $over = []): string
    {
        $p = app(PartyPaymentService::class)->record(User::query()->findOrFail($this->accountantId), array_merge(['party_type' => 'customer', 'party_id' => $this->customerId, 'method' => 'cash', 'amount' => $amount], $over));

        return 'OK:'.$p->id;
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

    /** @param  array<int, string>  $results @return array<int, string> */
    private function failures(array $results): array
    {
        return array_values(array_filter($results, fn ($r) => ! str_starts_with($r, 'OK:')));
    }

    public function test_two_receipts_cannot_allocate_more_to_an_invoice_than_it_owes(): void
    {
        $sale = $this->dueInvoice();
        $split = ['auto_oldest_first' => false, 'allocations' => [['doc_table' => 'sales', 'doc_id' => $sale->id, 'amount' => '800.00']]];

        $results = $this->race(6, fn () => $this->receive('800.00', $split));

        $this->assertSame(1, $this->oks($results), json_encode($results));
        $this->assertSame(array_fill(0, 5, 'ALLOCATION_EXCEEDS_DUE'), $this->failures($results), json_encode($results));
        $row = DB::table('sales')->where('id', $sale->id)->first();
        $this->assertSame(['800.00', '200.00', 'partial'], [$row->paid_total, $row->due_total, $row->payment_status]);
        $this->assertSame(1, PartyPayment::query()->count());
        $this->assertBooksHold();
    }

    public function test_automatic_receipts_in_parallel_settle_the_invoice_exactly_once_and_keep_the_rest_as_advance(): void
    {
        $sale = $this->dueInvoice();

        $results = $this->race(6, fn () => $this->receive('400.00'));

        $this->assertSame(6, $this->oks($results), json_encode($results));
        $row = DB::table('sales')->where('id', $sale->id)->first();
        $this->assertSame(['1000.00', '0.00', 'paid'], [$row->paid_total, $row->due_total, $row->payment_status], 'allocated exactly the 1,000 that was due');
        $this->assertSame('1400.00', number_format((float) DB::table('party_payments')->sum('advance_amount'), 2, '.', ''));
        $this->assertSame('2400.00', number_format((float) DB::table('account_transactions')->where('direction', 'in')->sum('amount'), 2, '.', ''));
        $this->assertBooksHold();
    }

    public function test_the_same_trxid_in_parallel_receipts_is_accepted_once(): void
    {
        $this->dueInvoice();
        $ref = ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'FIN-RACE'];

        $results = $this->race(6, fn () => $this->receive('100.00', $ref));

        $this->assertSame(1, $this->oks($results), json_encode($results));
        $this->assertSame(array_fill(0, 5, 'DUPLICATE_TRXID'), $this->failures($results), json_encode($results));
        $this->assertSame(1, DB::table('party_payments')->where('trx_id', 'FIN-RACE')->count());
        $this->assertSame(1, DB::table('account_transactions')->where('ref_type', 'party_payment')->count(), 'refused receipts put nothing in the books');
        $this->assertBooksHold();
    }

    public function test_an_account_cannot_be_spent_twice(): void
    {
        DB::table('financial_accounts')->where('account_type', 'bkash')->update(['opening_balance' => '1000.00']);
        $bkash = (int) DB::table('financial_accounts')->where('account_type', 'bkash')->value('id');
        $bank = (int) DB::table('financial_accounts')->where('account_type', 'bank')->value('id');

        $results = $this->race(6, function () use ($bkash, $bank): string {
            $t = app(FundTransferService::class)->create(User::query()->findOrFail($this->accountantId), ['from_account_id' => $bkash, 'to_account_id' => $bank, 'amount' => '800.00']);

            return 'OK:'.$t->id;
        });

        $this->assertSame(1, $this->oks($results), json_encode($results));
        $this->assertSame(array_fill(0, 5, 'INSUFFICIENT_FUNDS'), $this->failures($results), json_encode($results));
        $this->assertSame(1, DB::table('fund_transfers')->count());
        $balance = fn (int $id) => (float) DB::table('financial_accounts')->where('id', $id)->value('opening_balance')
            + (float) DB::table('account_transactions')->where('account_id', $id)->where('direction', 'in')->sum('amount') - (float) DB::table('account_transactions')->where('account_id', $id)->where('direction', 'out')->sum('amount');
        $this->assertSame([200.0, 800.0], [$balance($bkash), $balance($bank)]);
        $this->assertBooksHold();
    }

    public function test_a_receipt_and_a_write_off_cannot_both_settle_the_same_due(): void
    {
        $this->dueInvoice();

        $results = $this->race(6, function (): string {
            // Half the racers collect 700 against the one invoice, half forgive 700 of it: only one of them can fit in the 1,000.
            if (random_int(0, 1) === 0) {
                return $this->receive('700.00', ['auto_oldest_first' => false, 'allocations' => [['doc_table' => 'sales', 'doc_id' => (int) DB::table('sales')->value('id'), 'amount' => '700.00']]]);
            }
            app(PartyAdjustmentService::class)->writeOff('customer', $this->customerId, '700.00', 'writeoff', 'race');

            return 'OK:writeoff';
        });

        $this->assertSame(1, $this->oks($results), json_encode($results));
        $row = DB::table('sales')->first();
        $this->assertSame('1000.00', number_format((float) $row->paid_total + (float) $row->due_total + (float) $row->written_off_total, 2, '.', ''));
        $this->assertSame('300.00', $row->due_total);
        $this->assertBooksHold();
    }

    public function test_payment_numbers_stay_gap_free_when_some_parallel_receipts_fail(): void
    {
        $this->dueInvoice();
        $bad = ['method' => 'bkash', 'sender_number' => '01712345678'];

        $results = $this->race(10, fn (int $i) => $i % 2 === 0 ? $this->receive('50.00') : $this->receive('50.00', $bad));

        $this->assertSame(5, $this->oks($results), json_encode($results));
        $this->assertSame(array_fill(0, 5, 'PAYMENT_REFERENCE_REQUIRED'), $this->failures($results));
        $numbers = PartyPayment::query()->orderBy('id')->pluck('payment_no')->map(fn ($n) => (int) substr((string) $n, -5))->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4, 5], $numbers, 'no gaps and no duplicates (BR-070)');
        $this->assertBooksHold();
    }
}
