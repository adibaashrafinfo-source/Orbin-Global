<?php

namespace Tests\Feature\Inventory;

use App\Models\Notification;
use App\Models\StockMovement;
use App\Services\Inventory\AlertService;
use App\Services\Inventory\StockPostingService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\SupplierService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryReadsTest extends InventoryTestCase
{
    /** Back-date every movement of a variant by N days (the ledger is append-only, so the test goes around the model). */
    private function age(int $variant, int $days): void
    {
        DB::table('stock_movements')->where('variant_id', $variant)->update(['occurred_at' => now()->subDays($days)]);
    }

    public function test_overview_status_flags_filters_and_kpis(): void
    {
        [$ok, $low, $out, $over] = $this->variants(4);
        $this->actingAsRole('super_admin');
        $this->putJson("/api/v1/variants/{$low}", ['retail_price' => '1000'])->assertOk();
        DB::table('product_variants')->where('id', $ok)->update(['reorder_level' => 2]);
        DB::table('product_variants')->where('id', $low)->update(['reorder_level' => 5, 'reorder_qty' => 20]);
        DB::table('product_variants')->where('id', $out)->update(['reorder_level' => 3]);
        DB::table('product_variants')->where('id', $over)->update(['max_stock' => 10]);
        $this->stock($ok, '10', '100');
        $this->stock($low, '4', '50');
        $this->stock($over, '12', '10');

        $rows = collect($this->getJson('/api/v1/inventory/overview?per_page=100')->assertOk()->json('data'))->keyBy('variant_id');
        $this->assertSame(['ok', 'low', 'out', 'over'], [$rows[$ok]['status'], $rows[$low]['status'], $rows[$out]['status'], $rows[$over]['status']]);
        $this->assertSame('10.000', $rows[$ok]['on_hand']);
        $this->assertSame(['100.0000', '1000.00'], [$rows[$ok]['avg_cost'], $rows[$ok]['stock_value']]);
        $this->getJson('/api/v1/inventory/overview?status=low')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.variant_id', $low);
        $this->getJson('/api/v1/inventory/overview?status=in')->assertOk()->assertJsonPath('meta.total', 3);
        $kpis = $this->getJson('/api/v1/inventory/overview')->json('meta.kpis');
        // value = 10×100 + 4×50 + 12×10 = 1,320
        $this->assertSame([3, '26.000', '1320.00', 1, 1], [$kpis['skus'], $kpis['units'], $kpis['value'], $kpis['low'], $kpis['out']]);
        $this->getJson('/api/v1/inventory/overview?warehouse_id='.$this->godown)->assertOk()->assertJsonPath('meta.kpis.skus', 0);

        // The storekeeper has no cost permission: the fields are not there at all.
        $this->actingAsRole('storekeeper');
        $row = $this->getJson('/api/v1/inventory/overview?per_page=100')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('avg_cost', $row);
        $this->assertArrayNotHasKey('stock_value', $row);
        $this->assertArrayNotHasKey('value', $this->getJson('/api/v1/inventory/overview')->json('meta.kpis'));
    }

    public function test_dead_stock_is_stock_that_has_not_sold_for_the_configured_days(): void
    {
        [$fresh, $old, $sold] = $this->variants(3);
        $this->actingAsRole('super_admin');
        foreach ([$fresh, $old, $sold] as $v) {
            $this->stock($v, '5', '10');
        }
        $this->age($old, 120);
        $this->age($sold, 120);
        DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $sold, 'warehouse_id' => $this->showroom, 'type' => 'sale', 'qty' => '-1']));
        $ids = collect($this->getJson('/api/v1/inventory/overview?status=dead')->assertOk()->json('data'))->pluck('variant_id')->all();
        $this->assertSame([$old], $ids, 'default is 90 days; the recent sale and the new stock are not dead');
    }

    public function test_ledger_filters_and_stock_card_running_balance(): void
    {
        [$a] = $this->variants();
        $this->actingAsRole('manager');
        $this->stock($a, '10', '100');
        DB::transaction(function () use ($a) {
            $s = app(StockPostingService::class);
            $s->post(['variant_id' => $a, 'warehouse_id' => $this->showroom, 'type' => 'sale', 'qty' => '-3', 'ref_no' => 'INV-1']);
            $s->post(['variant_id' => $a, 'warehouse_id' => $this->godown, 'type' => 'opening', 'qty' => '5', 'unit_cost' => '90']);
            $s->post(['variant_id' => $a, 'warehouse_id' => $this->showroom, 'type' => 'breakage', 'qty' => '-1']);
        });
        $this->getJson("/api/v1/inventory/movements?variant_id={$a}")->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.movement_type', 'breakage');
        $this->getJson("/api/v1/inventory/movements?variant_id={$a}&type[]=sale&type[]=breakage")->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/inventory/movements?variant_id={$a}&ref_no=INV-1")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/inventory/movements?variant_id={$a}&warehouse_id={$this->godown}")->assertOk()->assertJsonPath('meta.total', 1);

        $card = $this->getJson("/api/v1/inventory/stock-card/{$a}?warehouse_id={$this->showroom}")->assertOk()->json('data');
        $this->assertSame(['0.000', '6.000'], [$card['opening'], $card['closing']]);
        $this->assertSame(['10.000', '7.000', '6.000'], array_column($card['rows'], 'running'));
        $all = $this->getJson("/api/v1/inventory/stock-card/{$a}")->json('data');
        $this->assertSame('11.000', $all['closing'], 'across warehouses: 6 + 5');

        // A window starting tomorrow opens with the full balance and shows nothing.
        $tomorrow = Carbon::now('Asia/Dhaka')->addDay()->toDateString();
        $later = $this->getJson("/api/v1/inventory/stock-card/{$a}?warehouse_id={$this->showroom}&from={$tomorrow}")->json('data');
        $this->assertSame(['6.000', 0], [$later['opening'], count($later['rows'])]);
        $csv = $this->get("/api/v1/inventory/stock-card/{$a}?format=csv")->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString('sale', file_get_contents($csv->baseResponse->getFile()->getPathname()));
    }

    public function test_valuation_as_of_a_date_replays_the_history_and_equals_the_live_stock_today(): void
    {
        [$a, $b] = $this->variants(2);
        $this->actingAsRole('super_admin');
        $s = app(SupplierService::class)->create(['name' => 'S']);
        $grn = fn (string $qty, string $cost, int $v) => app(GoodsReceiptService::class)->post(app(GoodsReceiptService::class)->create([
            'warehouse_id' => $this->showroom, 'supplier_id' => $s->id, 'received_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $v, 'qty_received' => $qty, 'unit_cost' => $cost]],
        ]));
        $grn('10', '100', $a);
        // Everything so far happened 10 days ago.
        DB::table('stock_movements')->update(['occurred_at' => now()->subDays(10)]);
        $grn('10', '120', $a);   // today: average becomes 110
        $grn('5', '40', $b);
        DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $a, 'warehouse_id' => $this->showroom, 'type' => 'sale', 'qty' => '-4']));

        $then = $this->getJson('/api/v1/inventory/valuation?as_of='.now('Asia/Dhaka')->subDays(5)->toDateString())->assertOk()->json('data');
        $this->assertSame('1000.00', $then['total'], 'five days ago only the first receipt existed: 10 × 100');
        $now = $this->getJson('/api/v1/inventory/valuation')->assertOk()->json('data');
        // A: 16 units at 110 = 1,760 · B: 5 × 40 = 200
        $this->assertSame('1960.00', $now['total']);
        $liveValue = DB::table('stock_levels')->selectRaw('ROUND(SUM(qty_on_hand * avg_cost), 2) as v')->value('v');
        $this->assertSame($liveValue, $now['total'], 'the replay equals what stock_levels says');
        $this->assertSame('110.0000', collect($now['rows'])->firstWhere('variant_id', $a)['avg_cost']);

        $this->actingAsRole('storekeeper'); // has inventory.reports but no cost permission
        $this->getJson('/api/v1/inventory/valuation')->assertStatus(403);
        $this->actingAsRole('accountant');
        $this->getJson('/api/v1/inventory/valuation')->assertStatus(403); // no inventory.reports
    }

    public function test_aging_buckets_use_the_newest_receipts_first(): void
    {
        [$a] = $this->variants();
        $this->actingAsRole('super_admin');
        $post = fn (string $type, string $qty, ?string $cost, int $daysAgo) => DB::table('stock_movements')->where('id', DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $a, 'warehouse_id' => $this->showroom, 'type' => $type, 'qty' => $qty, 'unit_cost' => $cost])->id))->update(['occurred_at' => now()->subDays($daysAgo)]);
        $post('opening', '10', '100', 200);          // 200 days old
        $post('purchase_receipt', '6', '100', 70);   // 70 days old
        $post('purchase_receipt', '4', '100', 5);    // 5 days old
        $post('sale', '-8', null, 1);                // 8 gone; the newest 10 units are what remains... 12 left
        $aging = $this->getJson('/api/v1/inventory/aging')->assertOk()->json('data');
        // 12 on the shelf matched to the newest receipts: 4 (5 days) + 6 (70 days) + 2 (200 days).
        $this->assertSame('4.000', $aging['buckets']['0-30']['qty']);
        $this->assertSame('6.000', $aging['buckets']['61-90']['qty']);
        $this->assertSame('2.000', $aging['buckets']['180+']['qty']);
        $this->assertSame('1200.00', number_format(collect($aging['buckets'])->sum(fn ($b) => (float) $b['value']), 2, '.', ''), '12 units at an average of 100');
        $this->actingAsRole('storekeeper');
        $this->assertArrayNotHasKey('value', $this->getJson('/api/v1/inventory/aging')->json('data.buckets.0-30'));
    }

    public function test_breakage_register_groups_cost_impact_and_finds_the_supplier(): void
    {
        [$a] = $this->variants();
        $this->actingAsRole('manager');
        $s = app(SupplierService::class)->create(['name' => 'Cracky Glass']);
        // Two units arrive broken on a receipt (landed at 100), one more breaks later and is claimed against the same supplier.
        $g = app(GoodsReceiptService::class);
        $g->post($g->create(['warehouse_id' => $this->showroom, 'supplier_id' => $s->id, 'received_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $a, 'qty_received' => '10', 'qty_damaged' => '2', 'unit_cost' => '100']]]));
        $adj = $this->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $this->showroom, 'reason_id' => $this->reasonId('breakage'), 'items' => [['variant_id' => $a, 'qty_delta' => '-1', 'supplier_id' => $s->id]]])->assertCreated()->json('data');
        $this->postJson("/api/v1/stock-adjustments/{$adj['id']}/post")->assertOk();

        $res = $this->getJson('/api/v1/inventory/breakage')->assertOk();
        $res->assertJsonPath('meta.count', 2)->assertJsonPath('meta.cost_impact', '300.00');
        $this->assertSame([$s->id, $s->id], collect($res->json('data'))->pluck('supplier_id')->all());
        $this->getJson("/api/v1/inventory/breakage?supplier_id={$s->id}")->assertJsonPath('meta.count', 2);
        $this->getJson('/api/v1/inventory/breakage?supplier_id=999999')->assertJsonPath('meta.count', 0);
        $this->actingAsRole('storekeeper');
        $this->assertArrayNotHasKey('cost_impact', $this->getJson('/api/v1/inventory/breakage')->json('meta'));
    }

    public function test_reorder_suggestions_group_by_last_supplier_and_create_one_draft_per_supplier(): void
    {
        [$a, $b, $c] = $this->variants(3);
        $this->actingAsRole('manager');
        $s1 = app(SupplierService::class)->create(['name' => 'Alpha']);
        $s2 = app(SupplierService::class)->create(['name' => 'Beta']);
        $g = app(GoodsReceiptService::class);
        foreach ([[$a, $s1, '2'], [$b, $s2, '1']] as [$v, $s, $q]) {
            $g->post($g->create(['warehouse_id' => $this->showroom, 'supplier_id' => $s->id, 'received_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $v, 'qty_received' => $q, 'unit_cost' => '50']]]));
        }
        DB::table('product_variants')->where('id', $a)->update(['reorder_level' => 5, 'reorder_qty' => 20]);
        DB::table('product_variants')->where('id', $b)->update(['reorder_level' => 3, 'reorder_qty' => 0]);
        DB::table('product_variants')->where('id', $c)->update(['reorder_level' => 4, 'reorder_qty' => 10]); // never bought: no supplier

        $list = collect($this->getJson('/api/v1/inventory/reorder')->assertOk()->json('data'))->keyBy('variant_id');
        $this->assertCount(3, $list);
        $this->assertSame(['Alpha', '20.000'], [$list[$a]['supplier'], $list[$a]['suggested_qty']]);
        $this->assertSame('2.000', $list[$b]['suggested_qty'], 'no reorder quantity set: top up to the level');
        $this->assertNull($list[$c]['supplier_id']);

        $res = $this->postJson('/api/v1/inventory/reorder/purchase-orders', ['lines' => [
            ['variant_id' => $a, 'qty' => '20', 'supplier_id' => $s1->id], ['variant_id' => $c, 'qty' => '10', 'supplier_id' => $s1->id], ['variant_id' => $b, 'qty' => '2', 'supplier_id' => $s2->id],
        ]])->assertCreated();
        $this->assertCount(2, $res->json('data'));
        $po = $this->getJson('/api/v1/purchase-orders/'.$res->json('data.0.id'))->assertOk()->json('data');
        $this->assertSame(['draft', 2], [$po['status'], count($po['items'])]);
        $this->actingAsRole('storekeeper');
        $this->postJson('/api/v1/inventory/reorder/purchase-orders', ['lines' => [['variant_id' => $a, 'qty' => '1', 'supplier_id' => $s1->id]]])->assertStatus(403);
        $this->assertArrayNotHasKey('last_cost', $this->getJson('/api/v1/inventory/reorder')->json('data.0'));
    }

    public function test_the_alert_run_raises_each_bell_entry_once(): void
    {
        [$a, $b] = $this->variants(2);
        $this->actingAsRole('manager');
        DB::table('product_variants')->where('id', $a)->update(['reorder_level' => 5]);
        DB::table('product_variants')->where('id', $b)->update(['reorder_level' => 5]);
        $this->stock($a, '2', '10');
        $counts = app(AlertService::class)->run();
        $this->assertSame([1, 1], [$counts['low'], $counts['out']]);
        $this->assertSame(1, Notification::query()->where(['type' => 'low_stock', 'role' => 'manager', 'entity_id' => $a])->count());
        app(AlertService::class)->run();
        $this->assertSame(1, Notification::query()->where(['type' => 'low_stock', 'role' => 'manager', 'entity_id' => $a])->count(), 'unread entries are not repeated');
        Notification::query()->where('type', 'low_stock')->update(['is_read' => true]);
        app(AlertService::class)->run();
        $this->assertSame(2, Notification::query()->where(['type' => 'low_stock', 'role' => 'manager', 'entity_id' => $a])->count(), 'once read, a new one can be raised');
        $this->artisan('stock:alerts')->assertSuccessful();
    }

    public function test_inventory_is_read_only_without_the_write_permissions(): void
    {
        [$a] = $this->variants();
        $this->actingAsRole('accountant');
        $this->getJson('/api/v1/inventory/overview')->assertOk();
        $this->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $this->showroom, 'reason_id' => $this->reasonId('found'), 'items' => [['variant_id' => $a, 'qty_delta' => '1']]])->assertStatus(403);
        $this->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $this->showroom, 'to_warehouse_id' => $this->godown, 'items' => [['variant_id' => $a, 'qty_sent' => '1']]])->assertStatus(403);
        $this->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->showroom])->assertStatus(403);
        $this->actingAsRole('cashier');
        $this->getJson('/api/v1/inventory/movements')->assertOk();
        $this->postJson('/api/v1/stock-adjustments', [])->assertStatus(403);
        $this->assertSame(0, StockMovement::query()->count());
    }
}
