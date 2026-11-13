<?php

namespace Tests\Feature\Sales;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\CompanyProfile;
use App\Models\ProductVariant;
use App\Models\RolePermission;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\VariantBundleItem;
use App\Services\Inventory\IntegrityService;
use App\Services\PermissionService;
use App\Services\Sales\SaleService;
use App\Services\Sales\ShiftService;
use App\Services\SettingsService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-711 and the checkout error codes: each one has its own test, and nothing partial is ever left behind. */
class SaleServiceTest extends SalesTestCase
{
    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (ApiException $e) {
            return $e->errorCode;
        }

        return 'NO_ERROR';
    }

    private function setting(string $key, mixed $value): void
    {
        app(SettingsService::class)->update([$key => $value]);
    }

    private function sumLedger(int $customerId): int
    {
        $r = DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $customerId])->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return Dec::parse((string) $r->d, 2) - Dec::parse((string) $r->c, 2);
    }

    public function test_a_cash_sale_posts_stock_invoice_payment_account_entry_and_ledger_together(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '2']));

        $this->assertSame('completed', $sale->status);
        $this->assertMatchesRegularExpression('/^ORB-INV-\d{4}-000001$/', $sale->invoice_no);
        $this->assertSame(['2000.00', '2000.00', '0.00', 'paid'], [$sale->grand_total, $sale->paid_total, $sale->due_total, $sale->payment_status]);
        $this->assertSame('8.000', $this->onHand($v));
        $this->assertSame($this->shift->id, $sale->shift_id);
        $this->assertNotNull($sale->verify_token);
        $this->assertNotNull($sale->pdf_path, 'the PDF is made right after the commit');

        $m = StockMovement::query()->where(['ref_table' => 'sales', 'ref_id' => $sale->id])->get();
        $this->assertCount(1, $m);
        $this->assertSame(['sale', '-2.000', '600.0000'], [$m[0]->movement_type, $m[0]->qty_delta, $m[0]->unit_cost]);

        $item = $sale->items()->first();
        $this->assertSame('600.0000', $item->unit_cost, 'COGS snapshot is the average cost at that moment (BR-031)');
        $this->assertSame('1000.00', $item->unit_price);
        $this->assertSame('2000.00', $item->line_total);
        $this->assertSame(6, $item->warranty_months);
        $this->assertNotNull($item->warranty_until);
        $this->assertSame('Right', ucfirst($item->spec_snapshot['position']));

        $tx = AccountTransaction::query()->where(['ref_type' => 'sale_payment'])->get();
        $this->assertCount(1, $tx);
        $this->assertSame(['in', '2000.00', $this->account('cash')->id], [$tx[0]->direction, $tx[0]->amount, $tx[0]->account_id]);
        $this->assertSame(0, $this->sumLedger($sale->customer_id), 'a fully paid sale leaves the customer at zero');
        $this->assertTrue(AuditLog::query()->where(['action' => 'sale_complete', 'entity_id' => $sale->id])->exists());
        $this->assertSame('Orbin Global', $sale->company_snapshot['legal_name']);
    }

    public function test_the_same_idempotency_key_returns_the_first_invoice_and_posts_nothing_twice(): void
    {
        [$v] = $this->stocked(1, '10');
        $payload = $this->payload([$v => '1']);
        $first = $this->checkout($payload);
        $second = $this->checkout($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('9.000', $this->onHand($v));
        $this->assertSame(1, SalePayment::query()->count());
    }

    public function test_a_wrong_client_total_is_refused_without_saving_anything(): void
    {
        [$v] = $this->stocked(1, '10');
        $this->assertSame('TOTAL_MISMATCH', $this->code(fn () => $this->checkout($this->payload([$v => '1'], ['client_expected_grand_total' => '999.00']))));
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000', $this->onHand($v));
    }

    public function test_insufficient_stock_rolls_back_everything_and_does_not_burn_an_invoice_number(): void
    {
        [$a, $b] = $this->stocked(2, '3');
        $this->assertSame('INSUFFICIENT_STOCK', $this->code(fn () => $this->checkout($this->payload([$a => '1', $b => '5']))));
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('3.000', $this->onHand($a), 'the first line must not stay deducted');
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'sale')->count());

        $sale = $this->checkout($this->payload([$a => '1']));
        $this->assertStringEndsWith('-000001', $sale->invoice_no, 'invoice numbers stay gap free (BR-070)');
    }

    public function test_a_sale_needs_an_open_shift_when_the_setting_says_so(): void
    {
        [$v] = $this->stocked(1, '5');
        app(ShiftService::class)->close($this->shift, [], null);
        $this->assertSame('NO_OPEN_SHIFT', $this->code(fn () => $this->checkout($this->payload([$v => '1']))));
        $this->setting('require_open_shift', false);
        $this->assertNull($this->checkout($this->payload([$v => '1']))->shift_id);
    }

    public function test_an_incomplete_company_profile_blocks_invoicing(): void
    {
        [$v] = $this->stocked(1, '5');
        CompanyProfile::current()->forceFill(['address_line1' => ''])->save();
        $this->assertSame('COMPANY_PROFILE_INCOMPLETE', $this->code(fn () => $this->checkout($this->payload([$v => '1']))));
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_walk_in_cannot_buy_on_credit_but_a_named_customer_can_and_the_ledger_shows_it(): void
    {
        [$v] = $this->stocked(1, '10');
        $this->assertSame('WALKIN_DUE', $this->code(fn () => $this->checkout($this->payload([$v => '2'], ['payments' => [['method' => 'cash', 'amount' => '500.00']]]))));
        $c = $this->customer();
        $sale = $this->checkout($this->payload([$v => '2'], ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => '500.00']]]));
        $this->assertSame(['partial', '500.00', '1500.00'], [$sale->payment_status, $sale->paid_total, $sale->due_total]);
        $this->assertSame(150000, $this->sumLedger($c->id));
        $unpaid = $this->checkout($this->payload([$v => '1'], ['customer_id' => $c->id, 'payments' => []]));
        $this->assertSame(['unpaid', '0.00', '1000.00'], [$unpaid->payment_status, $unpaid->paid_total, $unpaid->due_total]);
        $this->assertSame(250000, $this->sumLedger($c->id));
    }

    public function test_split_payment_posts_each_method_to_its_own_account(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '5'], ['payments' => [
            ['method' => 'cash', 'amount' => '3000.00'],
            ['method' => 'bkash', 'amount' => '2000.00', 'sender_number' => '01712345678', 'trx_id' => 'tx-aa11'],
        ]]));
        $this->assertSame('paid', $sale->payment_status);
        $in = AccountTransaction::query()->where('ref_type', 'sale_payment')->orderBy('id')->get();
        $this->assertSame([$this->account('cash')->id, $this->account('bkash')->id], $in->pluck('account_id')->all());
        $this->assertSame('TX-AA11', SalePayment::query()->where('method', 'bkash')->value('trx_id'), 'TrxID is stored upper case');
        $this->assertSame(0, $this->sumLedger($sale->customer_id));
    }

    public function test_cash_tendered_above_the_amount_gives_change_and_books_only_the_amount(): void
    {
        [$v] = $this->stocked(1, '5');
        $sale = $this->checkout($this->payload([$v => '2'], ['payments' => [['method' => 'cash', 'amount' => '2000.00', 'tendered' => '5000.00']]]));
        $this->assertSame('3000.00', $sale->change_given);
        $this->assertSame('2000.00', $sale->paid_total);
        $this->assertSame('2000.00', AccountTransaction::query()->where('ref_type', 'sale_payment')->value('amount'));
        $this->assertSame('VALIDATION', $this->code(fn () => $this->checkout($this->payload([$v => '1'], ['payments' => [['method' => 'cash', 'amount' => '1000.00', 'tendered' => '500.00']]]))));
    }

    public function test_paying_more_than_the_bill_by_mobile_money_is_refused(): void
    {
        [$v] = $this->stocked(1, '5');
        $this->assertSame('OVERPAYMENT', $this->code(fn () => $this->checkout($this->payload([$v => '1'], ['payments' => [['method' => 'nagad', 'amount' => '1500.00', 'sender_number' => '01812345678', 'trx_id' => 'N1']]]))));
    }

    public function test_payment_references_are_required(): void
    {
        [$v] = $this->stocked(1, '5');
        $noTrx = fn () => $this->checkout($this->payload([$v => '1'], ['payments' => [['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678']]]));
        $badNumber = fn () => $this->checkout($this->payload([$v => '1'], ['payments' => [['method' => 'rocket', 'amount' => '1000.00', 'sender_number' => '12345', 'trx_id' => 'R1']]]));
        $noBankRef = fn () => $this->checkout($this->payload([$v => '1'], ['payments' => [['method' => 'bank_transfer', 'amount' => '1000.00', 'bank_name' => 'City Bank']]]));
        $this->assertSame('PAYMENT_REFERENCE_REQUIRED', $this->code($noTrx));
        $this->assertSame('PAYMENT_REFERENCE_REQUIRED', $this->code($badNumber));
        $this->assertSame('PAYMENT_REFERENCE_REQUIRED', $this->code($noBankRef));
        $ok = $this->checkout($this->payload([$v => '1'], ['payments' => [['method' => 'bank_transfer', 'amount' => '1000.00', 'bank_name' => 'City Bank', 'bank_ref' => 'CHQ-77']]]));
        $this->assertSame('City Bank', $ok->payments()->first()->bank_name);
    }

    public function test_a_duplicate_trxid_is_blocked_unless_a_manager_overrides_with_a_pin(): void
    {
        [$v] = $this->stocked(1, '10');
        $pay = fn (string $trx) => ['payments' => [['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678', 'trx_id' => $trx]]];
        $this->checkout($this->payload([$v => '1'], $pay('DUP1')));
        $this->assertSame('DUPLICATE_TRXID', $this->code(fn () => $this->checkout($this->payload([$v => '1'], $pay('dup1')))));
        $this->assertSame('PIN_INVALID', $this->code(fn () => $this->checkout($this->payload([$v => '1'], $pay('DUP1') + ['approvals' => ['manager_pin' => '0000']]))));
        $sale = $this->checkout($this->payload([$v => '1'], $pay('DUP1') + ['approvals' => ['manager_pin' => '2345']]));
        $p = $sale->payments()->first();
        $this->assertSame($this->userWithRole('manager')->id, $p->duplicate_override_by);
        $this->assertNull($p->trx_key);
        $this->assertSame(1, AuditLog::query()->where(['action' => 'sale_complete', 'entity_id' => $sale->id])->whereNotNull('approver_id')->count());
    }

    public function test_the_same_trxid_twice_in_one_cart_counts_as_a_duplicate(): void
    {
        [$v] = $this->stocked(1, '10');
        $line = ['method' => 'nagad', 'amount' => '1000.00', 'sender_number' => '01812345678', 'trx_id' => 'SAME'];
        $this->assertSame('DUPLICATE_TRXID', $this->code(fn () => $this->checkout($this->payload([$v => '2'], ['payments' => [$line, $line]]))));
    }

    public function test_a_discount_over_the_cashier_limit_needs_a_manager_pin(): void
    {
        [$v] = $this->stocked(1, '10');
        $cart = ['lines' => [['variant_id' => $v, 'qty' => '1', 'unit_price' => '1000.00', 'discount_type' => 'percent', 'discount_value' => '10']], 'client_expected_grand_total' => '900.00', 'payments' => [['method' => 'cash', 'amount' => '900.00']]];
        $this->assertSame('PIN_REQUIRED', $this->code(fn () => $this->checkout($this->payload([$v => '1'], $cart))));
        $this->assertSame('PIN_INVALID', $this->code(fn () => $this->checkout($this->payload([$v => '1'], $cart + ['approvals' => ['manager_pin' => '9999']]))));
        $sale = $this->checkout($this->payload([$v => '1'], $cart + ['approvals' => ['manager_pin' => '2345', 'reason' => 'regular customer']]));
        $this->assertSame('900.00', $sale->grand_total);
        $log = AuditLog::query()->where(['action' => 'sale_complete', 'entity_id' => $sale->id])->first();
        $this->assertSame($this->userWithRole('manager')->id, $log->approver_id);
        $this->assertSame('regular customer', $log->reason);
    }

    public function test_a_discount_within_the_limit_needs_no_pin_and_a_manager_never_does(): void
    {
        [$v] = $this->stocked(1, '10');
        $five = ['lines' => [['variant_id' => $v, 'qty' => '1', 'unit_price' => '1000.00', 'discount_type' => 'percent', 'discount_value' => '5']], 'client_expected_grand_total' => '950.00', 'payments' => [['method' => 'cash', 'amount' => '950.00']]];
        $this->assertSame('950.00', $this->checkout($this->payload([$v => '1'], $five))->grand_total);
        $thirty = ['lines' => [['variant_id' => $v, 'qty' => '1', 'unit_price' => '1000.00', 'discount_type' => 'percent', 'discount_value' => '30']], 'client_expected_grand_total' => '700.00', 'payments' => [['method' => 'cash', 'amount' => '700.00']]];
        $manager = $this->userWithRole('manager');
        $this->shiftFor($manager);
        $this->assertSame('700.00', $this->checkout($this->payload([$v => '1'], $thirty), $manager)->grand_total);
    }

    public function test_pin_failures_lock_the_cashier_after_the_limit(): void
    {
        [$v] = $this->stocked(1, '10');
        $cart = ['lines' => [['variant_id' => $v, 'qty' => '1', 'unit_price' => '1000.00', 'discount_type' => 'percent', 'discount_value' => '50']], 'client_expected_grand_total' => '500.00', 'payments' => [['method' => 'cash', 'amount' => '500.00']]];
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->code(fn () => $this->checkout($this->payload([$v => '1'], $cart + ['approvals' => ['manager_pin' => '0001']])));
        }
        $this->assertSame(['PIN_INVALID', 'PIN_INVALID', 'PIN_INVALID', 'PIN_INVALID', 'PIN_LOCKED'], $codes);
        $this->assertSame('PIN_LOCKED', $this->code(fn () => $this->checkout($this->payload([$v => '1'], $cart + ['approvals' => ['manager_pin' => '2345']]))), 'even the right PIN is refused while locked');
    }

    public function test_selling_below_the_floor_price_needs_a_pin_and_a_price_change_needs_permission(): void
    {
        [$v] = $this->stocked(1, '10', ['min_price' => '900.00']);
        $low = ['lines' => [['variant_id' => $v, 'qty' => '1', 'unit_price' => '800.00']], 'client_expected_grand_total' => '800.00', 'payments' => [['method' => 'cash', 'amount' => '800.00']]];
        $this->assertSame('PIN_REQUIRED', $this->code(fn () => $this->checkout($this->payload([$v => '1'], $low))));
        $sale = $this->checkout($this->payload([$v => '1'], $low + ['approvals' => ['manager_pin' => '2345']]));
        $this->assertSame('800.00', $sale->items()->first()->unit_price);
        $this->assertNotNull($sale->items()->first()->price_override_by);
        // 950 is a legal override (above the floor) for a cashier; with the permission taken away it is refused.
        RolePermission::query()->where(['role' => 'cashier', 'permission_key' => 'pos.price_override'])->update(['allowed' => false]);
        app(PermissionService::class)->forget('cashier');
        $ok = ['lines' => [['variant_id' => $v, 'qty' => '1', 'unit_price' => '950.00']], 'client_expected_grand_total' => '950.00', 'payments' => [['method' => 'cash', 'amount' => '950.00']]];
        $this->assertSame('PRICE_OVERRIDE_FORBIDDEN', $this->code(fn () => $this->checkout($this->payload([$v => '1'], $ok))));
    }

    public function test_the_price_follows_the_customer_tier_and_falls_back_to_retail(): void
    {
        [$a, $b] = $this->stocked(2, '10');
        ProductVariant::query()->whereKey($a)->update(['wholesale_price' => '800.00']);
        $c = $this->customer(['price_tier' => 'wholesale']);
        $lines = [['variant_id' => $a, 'qty' => '1', 'unit_price' => '800.00'], ['variant_id' => $b, 'qty' => '1', 'unit_price' => '1000.00']];
        $sale = $this->checkout($this->payload([$a => '1'], ['lines' => $lines, 'customer_id' => $c->id, 'client_expected_grand_total' => '1800.00', 'payments' => [['method' => 'cash', 'amount' => '1800.00']]]));
        $items = $sale->items()->get();
        $this->assertSame(['wholesale', 'retail'], $items->pluck('price_tier_used')->all(), 'a tier with no price falls back to retail (BR-002)');
        $this->assertSame(['800.00', '1000.00'], $items->pluck('unit_price')->all());
    }

    public function test_vat_exclusive_and_cart_discount_are_computed_by_the_server(): void
    {
        [$v] = $this->stocked(1, '10');
        $this->setting('cashier_max_discount_percent', 100);
        DB::table('tax_rates')->where('code', 'VAT15')->update(['is_default' => false]);
        $this->setting('default_tax_rate_code', 'VAT15');
        // 2 × 1000 = 2000, cart discount 200 → 1800, VAT 15% = 270 → 2070.
        $sale = $this->checkout($this->payload([$v => '2'], ['cart_discount' => ['type' => 'amount', 'value' => '200.00'], 'client_expected_grand_total' => '2070.00', 'payments' => [['method' => 'cash', 'amount' => '2070.00']]]));
        $this->assertSame(['2000.00', '200.00', '1800.00', '270.00', '2070.00'], [$sale->subtotal, $sale->cart_discount_amount, $sale->taxable_amount, $sale->tax_total, $sale->grand_total]);
        $i = $sale->items()->first();
        $this->assertSame(['200.00', '270.00', '2070.00', '15.00'], [$i->cart_discount_share, $i->tax_amount, $i->line_total, $i->tax_rate_percent]);
    }

    public function test_credit_limit_in_block_mode_needs_a_manager_pin(): void
    {
        [$v] = $this->stocked(1, '10');
        $this->setting('credit_limit_mode', 'block');
        $c = $this->customer(['credit_limit' => '5000.00']);
        $on = ['customer_id' => $c->id, 'payments' => []];
        $this->assertSame('PIN_REQUIRED', $this->code(fn () => $this->checkout($this->payload([$v => '6'], $on))));
        $this->assertSame('6000.00', $this->checkout($this->payload([$v => '6'], $on + ['approvals' => ['manager_pin' => '2345']]))->due_total);
    }

    public function test_credit_limit_in_warn_mode_never_blocks_the_server(): void
    {
        [$v] = $this->stocked(1, '10');
        $c = $this->customer(['credit_limit' => '100.00']);
        $this->assertSame('3000.00', $this->checkout($this->payload([$v => '3'], ['customer_id' => $c->id, 'payments' => []]))->due_total);
    }

    public function test_a_locked_period_blocks_new_sales(): void
    {
        [$v] = $this->stocked(1, '10');
        $this->setting('period_lock_date', now('Asia/Dhaka')->toDateString());
        $this->assertSame('PERIOD_LOCKED', $this->code(fn () => $this->checkout($this->payload([$v => '1']))));
    }

    public function test_an_inactive_variant_cannot_be_sold(): void
    {
        [$v] = $this->stocked(1, '10');
        ProductVariant::query()->whereKey($v)->update(['is_active' => false]);
        $this->assertSame('VARIANT_UNAVAILABLE', $this->code(fn () => $this->checkout($this->payload([$v => '1']))));
    }

    public function test_a_bundle_sells_as_one_priced_line_and_takes_its_components_out_of_stock(): void
    {
        [$bundle, $glass, $cap] = $this->stocked(3, '10');
        ProductVariant::query()->whereKey($bundle)->update(['is_bundle' => true]);
        VariantBundleItem::query()->create(['bundle_variant_id' => $bundle, 'component_variant_id' => $glass, 'qty' => '1']);
        VariantBundleItem::query()->create(['bundle_variant_id' => $bundle, 'component_variant_id' => $cap, 'qty' => '2']);
        $sale = $this->checkout($this->payload([$bundle => '2']));
        $this->assertSame('8.000', $this->onHand($glass));
        $this->assertSame('6.000', $this->onHand($cap));
        $items = $sale->items()->get();
        $this->assertCount(3, $items);
        $this->assertSame([null, 1, 1], $items->pluck('bundle_parent_line')->all());
        $this->assertSame('0.0000', $items[0]->unit_cost, 'the bundle row carries no cost; its components do (BR-033)');
        $this->assertSame('600.0000', $items[1]->unit_cost);
        $this->assertSame(['2000.00', '0.00', '0.00'], $items->pluck('line_total')->all());
    }

    public function test_every_money_and_stock_invariant_holds_after_a_mixed_day(): void
    {
        [$a, $b] = $this->stocked(2, '20');
        $c = $this->customer();
        $this->checkout($this->payload([$a => '3', $b => '2']));
        $this->checkout($this->payload([$a => '1'], ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => '400.00']]]));
        $this->checkout($this->payload([$b => '4'], ['payments' => [['method' => 'bkash', 'amount' => '4000.00', 'sender_number' => '01712345678', 'trx_id' => 'INV1']]]));
        foreach (Sale::query()->get() as $s) {
            $this->assertSame(Dec::parse((string) $s->grand_total, 2), Dec::parse((string) $s->paid_total, 2) + Dec::parse((string) $s->due_total, 2));
            $this->assertSame(Dec::parse((string) $s->paid_total, 2), Dec::parse((string) SalePayment::query()->where('sale_id', $s->id)->sum('amount'), 2));
            $this->assertSame(Dec::parse((string) $s->subtotal, 2) - Dec::parse((string) $s->line_discount_total, 2) - Dec::parse((string) $s->cart_discount_amount, 2) + Dec::parse((string) $s->tax_total, 2) * ($s->vat_mode_snapshot === 'exclusive' ? 1 : 0) + Dec::parse((string) $s->delivery_charge, 2) + Dec::parse((string) $s->round_off, 2), Dec::parse((string) $s->grand_total, 2));
        }
        foreach ([$a, $b] as $v) {
            $this->assertSame(Dec::parse((string) StockMovement::query()->where('variant_id', $v)->sum('qty_delta'), 3), Dec::parse($this->onHand($v), 3), 'stock on file equals the sum of movements');
        }
        $this->assertSame($this->sumLedger($c->id), Dec::parse((string) Sale::query()->where('customer_id', $c->id)->sum('due_total'), 2));
        $this->assertSame('ok', app(IntegrityService::class)->run()['status']);
    }

    public function test_a_completed_invoice_cannot_be_edited(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '1']));
        $this->expectException(\LogicException::class);
        $sale->forceFill(['grand_total' => '1.00'])->save();
    }

    public function test_voiding_restores_stock_at_its_cost_reverses_payments_and_keeps_the_invoice(): void
    {
        [$v] = $this->stocked(1, '10');
        $c = $this->customer();
        $sale = $this->checkout($this->payload([$v => '3'], ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => '2000.00']]]));
        $this->assertSame('7.000', $this->onHand($v));
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $void = app(SaleService::class)->void($manager, $sale, 'customer cancelled');

        $this->assertSame(['void', 'customer cancelled'], [$void->status, $void->void_reason]);
        $this->assertSame($manager->id, $void->voided_by);
        $this->assertSame('10.000', $this->onHand($v));
        $this->assertSame('600.0000', $this->avgCost($v), 'a void does not move the average cost');
        $back = StockMovement::query()->where(['ref_table' => 'sales', 'ref_id' => $sale->id, 'reason' => 'void'])->first();
        $this->assertSame(['sale_return', '3.000', '600.0000'], [$back->movement_type, $back->qty_delta, $back->unit_cost]);
        $this->assertSame(0, $this->sumLedger($c->id), 'invoice and payment are both reversed');
        $out = AccountTransaction::query()->where(['direction' => 'out', 'account_id' => $this->account('cash')->id])->first();
        $this->assertSame('2000.00', $out->amount);
        $this->assertNotNull($void->pdf_path);
        $this->assertTrue(AuditLog::query()->where(['action' => 'sale_void', 'entity_id' => $sale->id])->exists());
        $this->assertSame('ALREADY_VOID', $this->code(fn () => app(SaleService::class)->void($manager, $sale->refresh(), 'again')));
    }

    public function test_only_a_super_admin_may_void_on_a_later_day(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '1']));
        DB::table('sales')->where('id', $sale->id)->update(['sale_date' => now()->subDays(2)]);
        $manager = $this->userWithRole('manager');
        $this->assertSame('FORBIDDEN', $this->code(fn () => app(SaleService::class)->void($manager, $sale->refresh(), 'old one')));
        $admin = $this->userWithRole('super_admin');
        $this->actingAsUser($admin);
        $this->assertSame('void', app(SaleService::class)->void($admin, $sale->refresh(), 'old one')->status);
    }

    public function test_held_bills_are_parked_without_stock_numbers_or_money(): void
    {
        [$v] = $this->stocked(1, '10');
        $this->actingAsUser($this->cashier);
        $svc = app(SaleService::class);
        $held = $svc->hold($this->cashier, ['held_name' => 'Karim, Axio', 'payload' => ['lines' => [['variant_id' => $v, 'qty' => '1']]]]);
        $this->assertSame(['held', 'Karim, Axio', null], [$held->status, $held->held_name, $held->invoice_no]);
        $this->assertSame('10.000', $this->onHand($v));
        $cart = $svc->resume($held);
        $this->assertSame($v, $cart['lines'][0]['variant_id']);
        $this->assertSame(0, Sale::query()->where('status', 'held')->count());
        $this->assertSame(0, Sale::query()->whereNotNull('invoice_no')->count());
        $this->assertSame('INVALID_STATE', $this->code(fn () => $svc->resume($held->refresh())));
    }
}
