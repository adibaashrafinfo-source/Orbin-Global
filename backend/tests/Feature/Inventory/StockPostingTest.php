<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\ApiException;
use App\Models\AppSetting;
use App\Models\StockMovement;
use App\Services\Inventory\StockPostingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use LogicException;

class StockPostingTest extends InventoryTestCase
{
    private function mv(int $variant, string $type, string $qty, ?string $cost = null, ?int $warehouse = null): StockMovement
    {
        return DB::transaction(fn () => app(StockPostingService::class)->post([
            'variant_id' => $variant, 'warehouse_id' => $warehouse ?? $this->showroom, 'type' => $type, 'qty' => $qty, 'unit_cost' => $cost,
        ]));
    }

    public function test_posting_needs_a_transaction(): void
    {
        [$v] = $this->variants();
        // RefreshDatabase wraps the test in one transaction, so leave it to prove the guard.
        $level = DB::transactionLevel();
        for ($i = 0; $i < $level; $i++) {
            DB::rollBack();
        }
        try {
            $this->expectException(LogicException::class);
            app(StockPostingService::class)->post(['variant_id' => $v, 'warehouse_id' => $this->showroom, 'type' => 'opening', 'qty' => '1', 'unit_cost' => '1']);
        } finally {
            for ($i = 0; $i < $level; $i++) {
                DB::beginTransaction();
            }
        }
    }

    public function test_weighted_average_follows_receipts_and_ignores_sales(): void
    {
        [$v] = $this->variants();
        $m1 = $this->mv($v, 'purchase_receipt', '10', '100');
        $this->assertSame('10.000', $m1->balance_after);
        $this->assertSame('100.0000', $this->avgCost($v));

        $this->mv($v, 'purchase_receipt', '10', '120');
        $this->assertSame('110.0000', $this->avgCost($v), '(10×100 + 10×120) / 20');

        $sale = $this->mv($v, 'sale', '-5');
        $this->assertSame('110.0000', $sale->unit_cost, 'sales leave at the current average (BR-031)');
        $this->assertSame('15.000', $this->onHand($v));
        $this->assertSame('110.0000', $this->avgCost($v), 'sales do not change the average (BR-032)');

        $this->mv($v, 'purchase_receipt', '5', '130');
        $this->assertSame('115.0000', $this->avgCost($v), '(15×110 + 5×130) / 20');
        $this->assertSame('20.000', $this->onHand($v));
    }

    public function test_adjustments_and_corrections_keep_the_average(): void
    {
        [$v] = $this->variants();
        $this->mv($v, 'opening', '10', '200');
        $this->mv($v, 'adjustment_in', '5');
        $this->mv($v, 'count_correction', '-2');
        $this->mv($v, 'breakage', '-1');
        $this->assertSame('12.000', $this->onHand($v));
        $this->assertSame('200.0000', $this->avgCost($v));
        $this->assertSame('200.0000', $this->movements($v)[1]->unit_cost, 'found stock enters at the current average');
    }

    public function test_an_empty_shelf_adopts_the_first_cost(): void
    {
        [$v] = $this->variants();
        $this->mv($v, 'adjustment_in', '3', '50');
        $this->assertSame('50.0000', $this->avgCost($v));
        $this->mv($v, 'adjustment_out', '-3');
        $this->mv($v, 'purchase_receipt', '2', '70');
        $this->assertSame('70.0000', $this->avgCost($v), 'on an empty shelf the receipt cost becomes the average');
    }

    public function test_negative_stock_is_blocked_with_details(): void
    {
        [$v] = $this->variants();
        $this->mv($v, 'opening', '3', '10');
        try {
            $this->mv($v, 'sale', '-4');
            $this->fail('expected INSUFFICIENT_STOCK');
        } catch (ApiException $e) {
            $this->assertSame('INSUFFICIENT_STOCK', $e->errorCode);
            $this->assertSame('3.000', $e->context['available']);
            $this->assertSame('4.000', $e->context['requested']);
        }
        $this->assertSame('3.000', $this->onHand($v), 'nothing changed');
        $this->assertCount(1, $this->movements($v));
    }

    public function test_the_setting_can_allow_negative_stock(): void
    {
        [$v] = $this->variants();
        AppSetting::query()->where('key', 'allow_negative_stock')->update(['value' => 'true']);
        app(SettingsService::class)->forgetCache();
        $this->mv($v, 'sale', '-2');
        $this->assertSame('-2.000', $this->onHand($v));
        $this->mv($v, 'purchase_receipt', '5', '40');
        $this->assertSame('40.0000', $this->avgCost($v), 'a receipt into negative stock restarts the average at its cost');
        $this->assertSame('3.000', $this->onHand($v));
    }

    public function test_bundles_hold_no_stock_and_unknown_types_are_refused(): void
    {
        [$v, $component] = $this->variants(2);
        $this->putJson("/api/v1/variants/{$v}", ['is_bundle' => true])->assertOk();
        try {
            $this->mv($v, 'opening', '1', '1');
            $this->fail('expected BUNDLE_NO_STOCK');
        } catch (ApiException $e) {
            $this->assertSame('BUNDLE_NO_STOCK', $e->errorCode);
        }
        $this->expectException(LogicException::class);
        $this->mv($component, 'teleport', '1', '1');
    }

    public function test_a_failed_batch_leaves_no_trace(): void
    {
        [$a, $b] = $this->variants(2);
        $this->mv($a, 'opening', '5', '10');
        try {
            DB::transaction(fn () => app(StockPostingService::class)->postMany([
                ['variant_id' => $a, 'warehouse_id' => $this->showroom, 'type' => 'sale', 'qty' => '-1'],
                ['variant_id' => $b, 'warehouse_id' => $this->showroom, 'type' => 'sale', 'qty' => '-1'],
            ]));
            $this->fail('second line has no stock');
        } catch (ApiException $e) {
            $this->assertSame('INSUFFICIENT_STOCK', $e->errorCode);
        }
        $this->assertSame('5.000', $this->onHand($a), 'the first line was rolled back too');
    }

    public function test_movements_cannot_be_edited_or_deleted(): void
    {
        [$v] = $this->variants();
        $m = $this->mv($v, 'opening', '1', '1');
        $this->expectException(LogicException::class);
        $m->update(['qty_delta' => '5.000']);
    }

    public function test_stock_levels_always_equal_the_sum_of_movements(): void
    {
        [$a, $b] = $this->variants(2);
        $this->mv($a, 'opening', '10', '100');
        $this->mv($a, 'sale', '-3');
        $this->mv($a, 'purchase_receipt', '4', '110', $this->godown);
        $this->mv($b, 'opening', '7', '50');
        $this->mv($b, 'breakage', '-2');
        $drift = DB::table('stock_levels as l')->select('l.id')
            ->leftJoin('stock_movements as m', fn ($j) => $j->on('m.variant_id', '=', 'l.variant_id')->on('m.warehouse_id', '=', 'l.warehouse_id'))
            ->groupBy('l.id', 'l.qty_on_hand')->havingRaw('l.qty_on_hand <> COALESCE(SUM(m.qty_delta), 0)')->count();
        $this->assertSame(0, $drift);
    }
}
