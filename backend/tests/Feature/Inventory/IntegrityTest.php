<?php

namespace Tests\Feature\Inventory;

use App\Services\Inventory\IntegrityService;
use App\Services\Inventory\StockPostingService;
use App\Services\Purchasing\SupplierService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** The nightly check must pass on honest books and name the exact thing that was tampered with. */
class IntegrityTest extends InventoryTestCase
{
    private int $variant;

    private int $poId;

    /** Honest history: a purchase order received in two parts, a sale and a breakage. */
    private function books(): void
    {
        [$this->variant] = $this->variants();
        $s = app(SupplierService::class)->create(['name' => 'Honest Supplier', 'opening_balance' => '500']);
        $po = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $s->id, 'order_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $this->variant, 'qty_ordered' => '20', 'unit_cost' => '100']]])->assertCreated()->json('data');
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/approve")->assertOk();
        $this->poId = $po['id'];
        foreach ([['12', '0', '100'], ['8', '1', '110']] as [$qty, $dmg, $cost]) {
            $grn = $this->postJson('/api/v1/goods-receipts', ['warehouse_id' => $this->showroom, 'supplier_id' => $s->id, 'po_id' => $po['id'], 'received_date' => now('Asia/Dhaka')->toDateString(),
                'items' => [['po_item_id' => $po['items'][0]['id'], 'variant_id' => $this->variant, 'qty_received' => $qty, 'qty_damaged' => $dmg, 'unit_cost' => $cost]], 'extra_costs' => [['cost_type' => 'freight', 'amount' => '40']]])->assertCreated()->json('data');
            $this->postJson("/api/v1/goods-receipts/{$grn['id']}/post")->assertOk();
        }
        DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $this->variant, 'warehouse_id' => $this->showroom, 'type' => 'sale', 'qty' => '-3']));
        $adj = $this->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $this->showroom, 'reason_id' => $this->reasonId('breakage'), 'items' => [['variant_id' => $this->variant, 'qty_delta' => '-1']]])->assertCreated()->json('data');
        $this->postJson("/api/v1/stock-adjustments/{$adj['id']}/post")->assertOk();
    }

    /** @return array<string, string> check key → status */
    private function statuses(): array
    {
        return collect(app(IntegrityService::class)->run()['checks'])->pluck('status', 'key')->all();
    }

    public function test_honest_books_pass_every_check(): void
    {
        $this->books();
        $result = app(IntegrityService::class)->run();
        $this->assertSame('ok', $result['status'], json_encode($result['checks']));
        $this->assertCount(7, $result['checks']);
        $this->assertSame(0, $this->artisan('integrity:check')->run());
    }

    public function test_a_hand_edited_stock_level_is_found(): void
    {
        $this->books();
        DB::table('stock_levels')->where('variant_id', $this->variant)->update(['qty_on_hand' => '99.000']);
        $this->assertSame('fail', $this->statuses()['stock_levels_match_movements']);
        $this->artisan('integrity:check')->assertExitCode(1);
        $sample = app(IntegrityService::class)->last()['checks'][0]['samples'][0];
        $this->assertSame([$this->variant, '99.000'], [$sample['variant_id'], $sample['qty_on_hand']]);
    }

    public function test_a_tampered_balance_a_wrong_average_and_a_deleted_ledger_line_are_each_named(): void
    {
        $this->books();
        $last = DB::table('stock_movements')->where('variant_id', $this->variant)->max('id');
        DB::table('stock_movements')->where('id', $last)->update(['balance_after' => '77.000']);
        $this->assertSame('fail', $this->statuses()['movement_balance_chain']);
        DB::table('stock_movements')->where('id', $last)->update(['balance_after' => DB::raw('(SELECT qty_on_hand FROM stock_levels WHERE variant_id = '.$this->variant.' LIMIT 1)')]);
        $this->assertSame('ok', $this->statuses()['movement_balance_chain']);

        DB::table('stock_levels')->where('variant_id', $this->variant)->update(['avg_cost' => '1.0000']);
        $this->assertSame('fail', $this->statuses()['average_cost_matches_replay']);

        DB::table('party_ledger_entries')->where('entry_type', 'invoice')->limit(1)->delete();
        $this->assertSame('fail', $this->statuses()['supplier_ledger_matches_documents']);
    }

    public function test_a_wrong_receipt_total_and_wrong_order_progress_are_found(): void
    {
        $this->books();
        DB::table('goods_receipts')->where('status', 'posted')->limit(1)->update(['items_total' => '1.00']);
        $this->assertSame('fail', $this->statuses()['receipt_totals']);
        DB::table('purchase_order_items')->where('po_id', $this->poId)->update(['qty_received' => '3.000']);
        $this->assertSame('fail', $this->statuses()['order_progress_matches_receipts']);
    }

    public function test_negative_stock_fails_unless_the_shop_allows_it_then_it_only_warns(): void
    {
        $this->books();
        DB::table('stock_levels')->where('variant_id', $this->variant)->update(['qty_on_hand' => '-1.000']);
        $this->assertSame('fail', $this->statuses()['no_negative_stock']);
        DB::table('app_settings')->where('key', 'allow_negative_stock')->update(['value' => 'true']);
        app(SettingsService::class)->forgetCache();
        $this->assertSame('warn', $this->statuses()['no_negative_stock']);
    }

    public function test_the_admin_page_endpoints_show_the_last_run_and_are_for_system_admins(): void
    {
        $this->actingAsRole('super_admin');
        Cache::forget('orbin.integrity.last');
        $this->getJson('/api/v1/admin/health/integrity')->assertOk()->assertJsonPath('data', null);
        $run = $this->postJson('/api/v1/admin/health/integrity/run')->assertOk();
        $run->assertJsonPath('data.status', 'ok')->assertJsonCount(7, 'data.checks');
        $this->getJson('/api/v1/admin/health/integrity')->assertOk()->assertJsonPath('data.status', 'ok');
        foreach (['manager', 'storekeeper', 'accountant', 'cashier'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/admin/health/integrity')->assertStatus(403);
            $this->postJson('/api/v1/admin/health/integrity/run')->assertStatus(403);
        }
    }

    public function test_the_schedule_runs_the_check_and_the_alerts(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('integrity:check')->expectsOutputToContain('stock:alerts')->assertSuccessful();
    }
}
