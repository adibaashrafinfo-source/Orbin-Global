<?php

namespace Tests\Feature\Sales;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\VariantBundleItem;
use App\Services\Inventory\IntegrityService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Services\SettingsService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-715, BR-052: refund shares worked out by hand, stock going back or to breakage, and what a return may not do. */
class SaleReturnServiceTest extends SalesTestCase
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

    private function ledger(int $customerId): int
    {
        $r = DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $customerId])->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return Dec::parse((string) $r->d, 2) - Dec::parse((string) $r->c, 2);
    }

    /** A VAT 15% sale of 2 × 1000 with a 200 cart discount: line total 2070, tax 270, paid in full. */
    private function vatSale(int $v): Sale
    {
        app(SettingsService::class)->update(['cashier_max_discount_percent' => 100, 'default_tax_rate_code' => 'VAT15']);

        return $this->checkout($this->payload([$v => '2'], ['cart_discount' => ['type' => 'amount', 'value' => '200.00'], 'client_expected_grand_total' => '2070.00', 'payments' => [['method' => 'cash', 'amount' => '2070.00']]]));
    }

    /** @param  array<string, mixed>  $over */
    private function ret(Sale $sale, string $qty, string $mode = 'cash', string $condition = 'resellable', array $over = [])
    {
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);

        return app(SaleReturnService::class)->create($manager, $sale, array_merge(['items' => [['sale_item_id' => $sale->items()->first()->id, 'qty' => $qty, 'condition' => $condition]], 'refund_mode' => $mode], $over));
    }

    public function test_a_partial_return_refunds_its_share_of_price_discount_and_tax_and_restocks(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->vatSale($v);
        $this->assertSame('8.000', $this->onHand($v));

        $r = $this->ret($sale, '1');
        $this->assertSame(['posted', '1035.00', '135.00', '1035.00', '0.00', 'cash'], [$r->status, $r->refund_total, $r->tax_total, $r->refund_total, $r->ledger_credit_total, $r->refund_method]);
        $this->assertSame('900.00', $r->subtotal, 'goods value without tax: 1035 − 135');
        $this->assertSame('9.000', $this->onHand($v));
        $back = StockMovement::query()->where(['ref_table' => 'sale_returns', 'ref_id' => $r->id])->first();
        $this->assertSame(['sale_return', '1.000', '600.0000'], [$back->movement_type, $back->qty_delta, $back->unit_cost], 'goes back at the cost it left at');
        $this->assertSame('partially_returned', $sale->refresh()->status);
        $this->assertSame('1.000', $sale->items()->first()->qty_returned);
        $out = AccountTransaction::query()->where(['ref_type' => 'sale_refund', 'ref_id' => $r->id])->first();
        $this->assertSame(['out', '1035.00', $this->account('cash')->id], [$out->direction, $out->amount, $out->account_id]);
        $this->assertSame(0, $this->ledger($sale->customer_id), 'goods credited and cash refunded cancel out');
        $this->assertNotNull($r->pdf_path, 'the credit note is saved');
    }

    public function test_returning_the_rest_takes_the_exact_remainder_so_shares_add_up(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->vatSale($v);
        $this->ret($sale, '1');
        $second = $this->ret($sale->refresh(), '1');
        $this->assertSame('1035.00', $second->refresh()->refund_total);
        $this->assertSame('returned', $sale->refresh()->status);
        $sum = Dec::parse((string) DB::table('sale_return_items')->sum('line_total'), 2);
        $this->assertSame(Dec::parse($sale->items()->first()->line_total, 2), $sum, 'returns add up to the line, to the paisa');
        $this->assertSame('INVALID_STATE', $this->code(fn () => $this->ret($sale->refresh(), '1')), 'a fully returned invoice takes no more returns');
    }

    public function test_an_odd_line_still_adds_up_when_returned_in_thirds(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '3'], ['lines' => [['variant_id' => $v, 'qty' => '3', 'unit_price' => '1000.00', 'discount_type' => 'amount', 'discount_value' => '100.00']], 'client_expected_grand_total' => '2900.00', 'payments' => [['method' => 'cash', 'amount' => '2900.00']]]));
        foreach (['1', '1', '1'] as $q) {
            $this->ret($sale->refresh(), $q);
        }
        $this->assertSame(Dec::parse('2900.00', 2), Dec::parse((string) DB::table('sale_return_items')->sum('line_total'), 2));
        $shares = DB::table('sale_return_items')->orderBy('id')->pluck('line_total')->map(fn ($x) => Dec::parse((string) $x, 2))->all();
        $this->assertSame([96667, 96667, 96666], $shares, 'each third rounds half up and the last takes the remainder');
    }

    public function test_a_broken_return_goes_to_the_breakage_register_not_back_on_the_shelf(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '2']));
        $r = $this->ret($sale, '1', 'cash', 'broken');
        $this->assertSame('8.000', $this->onHand($v), 'the unit is not resellable, so shelf stock is unchanged');
        $m = StockMovement::query()->where(['ref_table' => 'sale_returns', 'ref_id' => $r->id])->orderBy('id')->get();
        $this->assertSame(['sale_return', 'breakage'], $m->pluck('movement_type')->all());
        $this->assertSame(['1.000', '-1.000'], $m->pluck('qty_delta')->all());
        $this->assertSame('600.0000', $m[1]->unit_cost, 'the loss shows at cost');
    }

    public function test_refund_is_capped_at_what_was_paid_and_the_rest_becomes_ledger_credit(): void
    {
        [$v] = $this->stocked(1, '10');
        $c = $this->customer();
        $sale = $this->checkout($this->payload([$v => '2'], ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => '500.00']]]));
        $this->assertSame(200000 - 50000, $this->ledger($c->id));
        $r = $this->ret($sale, '2');
        $this->assertSame(['500.00', '1500.00'], [$r->refund_total, $r->ledger_credit_total]);
        $this->assertSame(0, $this->ledger($c->id), 'owed 1500 after paying 500; credited 2000 and refunded 500 in cash, so even');
    }

    public function test_ledger_credit_mode_pays_nothing_out_and_leaves_the_customer_in_credit(): void
    {
        [$v] = $this->stocked(1, '10');
        $c = $this->customer();
        $sale = $this->checkout($this->payload([$v => '2'], ['customer_id' => $c->id]));
        $r = $this->ret($sale, '1', 'ledger_credit');
        $this->assertSame(['0.00', '1000.00', null], [$r->refund_total, $r->ledger_credit_total, $r->refund_method]);
        $this->assertSame(0, AccountTransaction::query()->where('ref_type', 'sale_refund')->count());
        $this->assertSame(-100000, $this->ledger($c->id));
    }

    public function test_the_return_window_is_enforced_for_cashiers_but_a_manager_can_override(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '2']));
        DB::table('sales')->where('id', $sale->id)->update(['sale_date' => now()->subDays(20)]);
        $cashier = $this->cashier;
        $this->actingAsUser($cashier);
        $item = $sale->items()->first();
        $this->assertSame('WINDOW_EXPIRED', $this->code(fn () => app(SaleReturnService::class)->create($cashier, $sale->refresh(), ['items' => [['sale_item_id' => $item->id, 'qty' => '1', 'condition' => 'resellable']], 'refund_mode' => 'cash'])));
        $this->assertSame('posted', $this->ret($sale->refresh(), '1')->status);
    }

    public function test_a_product_can_set_its_own_return_window(): void
    {
        [$v] = $this->stocked(1, '10');
        DB::table('products')->where('id', ProductVariant::query()->find($v)->product_id)->update(['return_window_days' => 30]);
        $sale = $this->checkout($this->payload([$v => '2']));
        DB::table('sales')->where('id', $sale->id)->update(['sale_date' => now()->subDays(20)]);
        $this->actingAsUser($this->cashier);
        $item = $sale->items()->first();
        $r = app(SaleReturnService::class)->create($this->cashier, $sale->refresh(), ['items' => [['sale_item_id' => $item->id, 'qty' => '1', 'condition' => 'resellable']], 'refund_mode' => 'cash']);
        $this->assertSame('pending_approval', $r->status);
    }

    public function test_a_cashier_return_waits_for_a_manager_who_then_posts_it_once(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '2']));
        $item = $sale->items()->first();
        $this->actingAsUser($this->cashier);
        $svc = app(SaleReturnService::class);
        $r = $svc->create($this->cashier, $sale, ['items' => [['sale_item_id' => $item->id, 'qty' => '1', 'condition' => 'resellable']], 'refund_mode' => 'cash']);
        $this->assertSame('pending_approval', $r->status);
        $this->assertSame('8.000', $this->onHand($v), 'nothing moves before approval');
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $posted = $svc->post($manager, $r);
        $this->assertSame(['posted', $manager->id], [$posted->status, $posted->approved_by]);
        $this->assertSame('9.000', $this->onHand($v));
        $this->assertSame('INVALID_STATE', $this->code(fn () => $svc->post($manager, $posted->refresh())));
    }

    public function test_two_pending_returns_cannot_together_return_more_than_was_sold(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '3']));
        $item = $sale->items()->first();
        $this->actingAsUser($this->cashier);
        $svc = app(SaleReturnService::class);
        $mk = fn () => $svc->create($this->cashier, $sale->refresh(), ['items' => [['sale_item_id' => $item->id, 'qty' => '2', 'condition' => 'resellable']], 'refund_mode' => 'cash']);
        $first = $mk();
        $second = $mk();
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $svc->post($manager, $first);
        $this->assertSame('QTY_EXCEEDS_SOLD', $this->code(fn () => $svc->post($manager, $second)));
        $this->assertSame('9.000', $this->onHand($v), 'only the first return put anything back: 10 − 3 sold + 2 returned');
    }

    public function test_a_sale_with_a_return_cannot_be_voided(): void
    {
        [$v] = $this->stocked(1, '10');
        $sale = $this->checkout($this->payload([$v => '2']));
        $this->ret($sale, '1');
        $manager = $this->userWithRole('manager');
        $this->assertSame('HAS_RETURNS', $this->code(fn () => app(SaleService::class)->void($manager, $sale->refresh(), 'oops')));
    }

    public function test_returning_a_bundle_puts_its_components_back(): void
    {
        [$bundle, $glass, $cap] = $this->stocked(3, '10');
        ProductVariant::query()->whereKey($bundle)->update(['is_bundle' => true]);
        VariantBundleItem::query()->create(['bundle_variant_id' => $bundle, 'component_variant_id' => $glass, 'qty' => '1']);
        VariantBundleItem::query()->create(['bundle_variant_id' => $bundle, 'component_variant_id' => $cap, 'qty' => '2']);
        $sale = $this->checkout($this->payload([$bundle => '2']));
        $this->assertSame(['8.000', '6.000'], [$this->onHand($glass), $this->onHand($cap)]);
        $this->ret($sale, '1');
        $this->assertSame(['9.000', '8.000'], [$this->onHand($glass), $this->onHand($cap)]);
        $this->assertSame('partially_returned', $sale->refresh()->status);
        $components = $sale->items()->whereNotNull('bundle_parent_line')->pluck('qty_returned')->all();
        $this->assertSame(['1.000', '2.000'], $components);
    }

    public function test_a_component_row_cannot_be_returned_on_its_own(): void
    {
        [$bundle, $glass] = $this->stocked(2, '10');
        ProductVariant::query()->whereKey($bundle)->update(['is_bundle' => true]);
        VariantBundleItem::query()->create(['bundle_variant_id' => $bundle, 'component_variant_id' => $glass, 'qty' => '1']);
        $sale = $this->checkout($this->payload([$bundle => '1']));
        $component = $sale->items()->whereNotNull('bundle_parent_line')->first();
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $this->assertSame('VALIDATION', $this->code(fn () => app(SaleReturnService::class)->create($manager, $sale, ['items' => [['sale_item_id' => $component->id, 'qty' => '1', 'condition' => 'resellable']], 'refund_mode' => 'cash'])));
    }

    public function test_the_books_still_balance_after_returns(): void
    {
        [$v] = $this->stocked(1, '20');
        $sale = $this->vatSale($v);
        $this->ret($sale, '1');
        $this->ret($sale->refresh(), '1', 'ledger_credit', 'broken');
        $this->assertSame('ok', app(IntegrityService::class)->run()['status']);
        $s = $sale->refresh();
        $this->assertSame(Dec::parse((string) $s->grand_total, 2), Dec::parse((string) $s->paid_total, 2) + Dec::parse((string) $s->due_total, 2), 'the invoice itself never changes');
    }
}
