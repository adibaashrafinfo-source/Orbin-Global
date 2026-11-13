<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\ApiException;
use App\Models\GoodsReceipt;
use App\Models\PartyLedgerEntry;
use App\Models\Supplier;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\SupplierService;
use Illuminate\Support\Facades\DB;

/** AT-011 and the rest of FR-503, with every figure worked out by hand in the comments. */
class GoodsReceiptServiceTest extends InventoryTestCase
{
    private function supplier(): Supplier
    {
        $this->actingAsRole('super_admin');

        return app(SupplierService::class)->create(['name' => 'Taiwan Mirror Co', 'supplier_type' => 'import']);
    }

    /** @param  array<int, array<string, mixed>>  $items  @param  array<int, array<string, mixed>>  $extras */
    private function receipt(Supplier $s, array $items, array $extras = [], string $basis = 'value', array $over = []): GoodsReceipt
    {
        return app(GoodsReceiptService::class)->create(array_merge([
            'warehouse_id' => $this->showroom, 'supplier_id' => $s->id, 'received_date' => now('Asia/Dhaka')->toDateString(),
            'allocation_basis' => $basis, 'items' => $items, 'extra_costs' => $extras,
        ], $over));
    }

    private function line(int $variant, string $qty, string $cost, string $damaged = '0'): array
    {
        return ['variant_id' => $variant, 'qty_received' => $qty, 'qty_damaged' => $damaged, 'unit_cost' => $cost];
    }

    public function test_landed_cost_by_value_matches_the_hand_calculation_and_the_average_moves(): void
    {
        // Existing stock: variant B holds 10 units at an average of 900.
        [$a, $b, $c] = $this->variants(3);
        $s = $this->supplier();
        DB::transaction(fn () => app(\App\Services\Inventory\StockPostingService::class)->post(['variant_id' => $b, 'warehouse_id' => $this->showroom, 'type' => 'opening', 'qty' => '10', 'unit_cost' => '900']));

        // Lines: A 100 × 500 = 50,000 · B 50 × 800 = 40,000 · C 20 × 500 = 10,000 → items 100,000.
        // Extras: freight 3,000 + customs 12,000 = 15,000, by value: A 7,500 · B 6,000 · C 1,500.
        $grn = $this->receipt($s, [$this->line($a, '100', '500'), $this->line($b, '50', '800'), $this->line($c, '20', '500')], [
            ['cost_type' => 'freight', 'amount' => '3000'], ['cost_type' => 'customs_duty', 'amount' => '12000'],
        ]);
        $this->assertSame('100000.00', $grn->items_total);
        $this->assertSame('15000.00', $grn->extra_cost_total);
        $this->assertSame('115000.00', $grn->grand_total);

        $posted = app(GoodsReceiptService::class)->post($grn);
        $by = $posted->items->keyBy('variant_id');
        // Landed: A (50,000 + 7,500) / 100 = 575 · B (40,000 + 6,000) / 50 = 920 · C (10,000 + 1,500) / 20 = 575.
        $this->assertSame(['7500.00', '575.0000'], [$by[$a]->allocated_extra, $by[$a]->landed_unit_cost]);
        $this->assertSame(['6000.00', '920.0000'], [$by[$b]->allocated_extra, $by[$b]->landed_unit_cost]);
        $this->assertSame(['1500.00', '575.0000'], [$by[$c]->allocated_extra, $by[$c]->landed_unit_cost]);

        $this->assertSame('100.000', $this->onHand($a));
        $this->assertSame('575.0000', $this->avgCost($a));
        // B blends: (10 × 900 + 50 × 920) / 60 = 55,000 / 60 = 916.6667.
        $this->assertSame('60.000', $this->onHand($b));
        $this->assertSame('916.6667', $this->avgCost($b));
        $this->assertSame('575.0000', $this->avgCost($c));
        $this->assertSame('920.00', DB::table('product_variants')->where('id', $b)->value('last_cost'));
        $this->assertSame('posted', $posted->status);
    }

    public function test_landed_cost_by_quantity(): void
    {
        [$a, $b] = $this->variants(2);
        $s = $this->supplier();
        // 10 × 100 and 30 × 200, extras 4,000 by quantity (10 : 30) → 1,000 and 3,000.
        $grn = app(GoodsReceiptService::class)->post($this->receipt($s, [$this->line($a, '10', '100'), $this->line($b, '30', '200')], [['cost_type' => 'freight', 'amount' => '4000']], 'quantity'));
        $by = $grn->items->keyBy('variant_id');
        $this->assertSame('1000.00', $by[$a]->allocated_extra);
        $this->assertSame('3000.00', $by[$b]->allocated_extra);
        $this->assertSame('200.0000', $by[$a]->landed_unit_cost, '(10 × 100 + 1,000) / 10');
        $this->assertSame('300.0000', $by[$b]->landed_unit_cost, '(30 × 200 + 3,000) / 30');
    }

    public function test_uneven_extras_are_split_to_the_paisa(): void
    {
        [$a, $b, $c] = $this->variants(3);
        $s = $this->supplier();
        // 100.00 over three equal lines: 33.34 + 33.33 + 33.33, and the three parts add up to exactly 100.00.
        $grn = app(GoodsReceiptService::class)->post($this->receipt($s, [$this->line($a, '1', '10'), $this->line($b, '1', '10'), $this->line($c, '1', '10')], [['cost_type' => 'other', 'amount' => '100']]));
        $parts = $grn->items->pluck('allocated_extra')->all();
        $this->assertSame(['33.34', '33.33', '33.33'], $parts);
        $this->assertSame('1.000', $this->onHand($a));
    }

    public function test_damaged_units_do_not_set_the_cost_and_leave_a_damage_trail(): void
    {
        [$a] = $this->variants();
        $s = $this->supplier();
        DB::transaction(fn () => app(\App\Services\Inventory\StockPostingService::class)->post(['variant_id' => $a, 'warehouse_id' => $this->showroom, 'type' => 'opening', 'qty' => '10', 'unit_cost' => '90']));
        // 10 arrive, 2 broken: good 8 × 100 = 800 + extra 80 → landed 110. Average: (10 × 90 + 8 × 110) / 18 = 1,780 / 18 = 98.8889.
        $grn = app(GoodsReceiptService::class)->post($this->receipt($s, [$this->line($a, '10', '100', '2')], [['cost_type' => 'freight', 'amount' => '80']]));
        $this->assertSame('110.0000', $grn->items[0]->landed_unit_cost);
        $this->assertSame('18.000', $this->onHand($a));
        $this->assertSame('98.8889', $this->avgCost($a));
        $types = array_map(fn ($m) => [$m->movement_type, $m->qty_delta], $this->movements($a));
        $this->assertSame([['opening', '10.000'], ['purchase_receipt', '8.000'], ['purchase_receipt', '2.000'], ['damage', '-2.000']], $types);
    }

    public function test_supplier_ledger_credit_covers_goods_and_billed_extras_only(): void
    {
        [$a] = $this->variants();
        $s = $this->supplier();
        $this->assertSame('0.00', app(SupplierService::class)->balance($s->id));
        // Goods 10,000 + freight 3,000 billed by the supplier; customs 12,000 paid to the C&F agent is not owed to the supplier.
        $grn = $this->receipt($s, [$this->line($a, '20', '500')], [
            ['cost_type' => 'freight', 'amount' => '3000'], ['cost_type' => 'customs_duty', 'amount' => '12000', 'paid_to' => 'C&F agent'],
        ]);
        $grn = app(GoodsReceiptService::class)->post($grn);
        $this->assertSame('13000.00', app(SupplierService::class)->balance($s->id));
        $entry = PartyLedgerEntry::query()->where(['party_type' => 'supplier', 'party_id' => $s->id])->firstOrFail();
        $this->assertSame(['invoice', '0.00', '13000.00', $grn->grn_no], [$entry->entry_type, $entry->debit, $entry->credit, $entry->ref_no]);
        // Landed cost still counts every extra: (10,000 + 15,000) / 20 = 1,250.
        $this->assertSame('1250.0000', $grn->items[0]->landed_unit_cost);
    }

    public function test_a_receipt_posts_once_and_a_second_post_is_refused(): void
    {
        [$a] = $this->variants();
        $s = $this->supplier();
        $grn = $this->receipt($s, [$this->line($a, '5', '10')]);
        app(GoodsReceiptService::class)->post($grn);
        try {
            app(GoodsReceiptService::class)->post($grn);
            $this->fail('expected INVALID_STATE');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_STATE', $e->errorCode);
        }
        $this->assertSame('5.000', $this->onHand($a));
        $this->assertSame(1, PartyLedgerEntry::query()->where('party_id', $s->id)->count());
    }

    public function test_empty_and_all_damaged_receipts_and_period_lock(): void
    {
        [$a] = $this->variants();
        $s = $this->supplier();
        $empty = $this->receipt($s, []);
        try {
            app(GoodsReceiptService::class)->post($empty);
            $this->fail('expected EMPTY_RECEIPT');
        } catch (ApiException $e) {
            $this->assertSame('EMPTY_RECEIPT', $e->errorCode);
        }
        // Extras with no good unit to carry them are refused rather than silently dropped.
        $allBroken = $this->receipt($s, [$this->line($a, '2', '10', '2')], [['cost_type' => 'freight', 'amount' => '50']]);
        try {
            app(GoodsReceiptService::class)->post($allBroken);
            $this->fail('expected EMPTY_RECEIPT');
        } catch (ApiException $e) {
            $this->assertSame('EMPTY_RECEIPT', $e->errorCode);
        }
        // Damaged more than received is a validation error.
        try {
            $this->receipt($s, [$this->line($a, '2', '10', '3')]);
            $this->fail('expected VALIDATION');
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION', $e->errorCode);
        }
        // BR-080: nothing dated on or before the lock date.
        DB::table('app_settings')->where('key', 'period_lock_date')->update(['value' => json_encode(now('Asia/Dhaka')->toDateString())]);
        app(\App\Services\SettingsService::class)->forgetCache();
        try {
            $this->receipt($s, [$this->line($a, '1', '1')]);
            $this->fail('expected PERIOD_LOCKED');
        } catch (ApiException $e) {
            $this->assertSame('PERIOD_LOCKED', $e->errorCode);
        }
    }

    public function test_preview_equals_what_posting_produces(): void
    {
        [$a, $b] = $this->variants(2);
        $s = $this->supplier();
        $items = [$this->line($a, '7', '123.45'), $this->line($b, '3', '99.99', '1')];
        $extras = [['cost_type' => 'freight', 'amount' => '77.77'], ['cost_type' => 'insurance', 'amount' => '10.01', 'paid_to' => 'Insurer']];
        $preview = app(GoodsReceiptService::class)->preview($items, $extras, 'value');
        $posted = app(GoodsReceiptService::class)->post($this->receipt($s, $items, $extras));
        foreach ($posted->items as $i => $item) {
            $this->assertSame($preview['lines'][$i]['allocated_extra'], $item->allocated_extra);
            $this->assertSame($preview['lines'][$i]['landed_unit_cost'], $item->landed_unit_cost);
        }
        $this->assertSame($preview['grand_total'], $posted->grand_total);
    }
}
