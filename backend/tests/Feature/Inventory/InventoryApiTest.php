<?php

namespace Tests\Feature\Inventory;

use App\Models\Notification;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use Illuminate\Http\UploadedFile;

class InventoryApiTest extends InventoryTestCase
{
    private function adjustment(array $items, string $reason = 'recount', array $over = []): array
    {
        return $this->postJson('/api/v1/stock-adjustments', array_merge(['warehouse_id' => $this->showroom, 'reason_id' => $this->reasonId($reason), 'items' => $items], $over))->assertCreated()->json('data');
    }

    public function test_a_small_adjustment_posts_at_once_with_the_right_movement_types(): void
    {
        [$a, $b, $c] = $this->variants(3);
        $this->stock($a, '10', '200');
        $this->actingAsRole('storekeeper');
        $adj = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-2'], ['variant_id' => $b, 'qty_delta' => '3', 'unit_cost' => '50']], 'found');
        $this->assertFalse($adj['requires_approval']);
        $this->assertArrayNotHasKey('total_value', $adj, 'the storekeeper has no cost permission');
        $this->assertArrayNotHasKey('unit_cost', $adj['items'][0]);
        $this->postJson("/api/v1/stock-adjustments/{$adj['id']}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('8.000', $this->onHand($a));
        $this->assertSame('3.000', $this->onHand($b));
        $this->assertSame('50.0000', $this->avgCost($b));
        $this->assertSame(['adjustment_out', '-2.000'], [$this->movements($a)[1]->movement_type, $this->movements($a)[1]->qty_delta]);
        $this->assertSame('adjustment_in', $this->movements($b)[0]->movement_type);

        $this->actingAsRole('super_admin');
        $this->getJson("/api/v1/stock-adjustments/{$adj['id']}")->assertOk()->assertJsonPath('data.total_value', '-250.00');
    }

    public function test_breakage_and_transit_damage_use_their_own_movement_types(): void
    {
        [$a] = $this->variants();
        $this->stock($a, '20', '100');
        $this->actingAsRole('storekeeper');
        $one = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-1']], 'breakage');
        $this->postJson("/api/v1/stock-adjustments/{$one['id']}/post")->assertOk();
        $two = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-1']], 'damaged_in_transit');
        $this->postJson("/api/v1/stock-adjustments/{$two['id']}/post")->assertOk();
        $three = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-1', 'is_breakage' => true]], 'lost_theft');
        $this->postJson("/api/v1/stock-adjustments/{$three['id']}/post")->assertOk();
        $types = array_map(fn ($m) => $m->movement_type, array_slice($this->movements($a), 1));
        $this->assertSame(['breakage', 'damage', 'breakage'], $types);
    }

    public function test_big_adjustments_wait_for_approval_by_quantity_or_by_value(): void
    {
        [$a, $b] = $this->variants(2);
        $this->stock($a, '100', '10');
        $this->stock($b, '100', '5000');
        $this->actingAsRole('storekeeper');
        // 6 units > the quantity limit of 5.
        $byQty = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-6']]);
        $this->assertTrue($byQty['requires_approval']);
        // 3 units but 15,000 at cost > the value limit of 10,000.
        $byValue = $this->adjustment([['variant_id' => $b, 'qty_delta' => '-3']]);
        $this->assertTrue($byValue['requires_approval']);
        $this->postJson("/api/v1/stock-adjustments/{$byQty['id']}/post")->assertStatus(422)->assertJsonPath('code', 'APPROVAL_REQUIRED');
        $this->assertSame('100.000', $this->onHand($a));
        $this->postJson("/api/v1/stock-adjustments/{$byQty['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->assertSame(1, Notification::query()->where(['type' => 'approval_pending', 'role' => 'manager', 'entity_id' => $byQty['id']])->count());
        $this->postJson("/api/v1/stock-adjustments/{$byQty['id']}/post")->assertStatus(422);
        $this->postJson("/api/v1/stock-adjustments/{$byQty['id']}/reject")->assertStatus(403);

        $this->actingAsRole('manager');
        $this->postJson("/api/v1/stock-adjustments/{$byQty['id']}/post")->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.approved_by', \Auth::id());
        $this->assertSame('94.000', $this->onHand($a));
        $this->postJson("/api/v1/stock-adjustments/{$byValue['id']}/submit")->assertOk();
        $this->postJson("/api/v1/stock-adjustments/{$byValue['id']}/reject", ['reason' => 'recount first'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('100.000', $this->onHand($b));
        $this->putJson("/api/v1/stock-adjustments/{$byValue['id']}", ['items' => [['variant_id' => $b, 'qty_delta' => '-1']]])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/v1/stock-adjustments/{$byValue['id']}/post")->assertOk();
        $this->assertSame('99.000', $this->onHand($b));
        $this->postJson("/api/v1/stock-adjustments/{$byValue['id']}/post")->assertStatus(409);
    }

    public function test_an_adjustment_cannot_take_stock_below_zero_and_needs_a_valid_reason(): void
    {
        [$a] = $this->variants();
        $this->stock($a, '2', '10');
        $this->actingAsRole('manager');
        $adj = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-3']]);
        $this->postJson("/api/v1/stock-adjustments/{$adj['id']}/post")->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK')->assertJsonPath('context.available', '2.000');
        $this->assertSame('draft', StockAdjustment::query()->find($adj['id'])->status);
        $this->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $this->showroom, 'reason_id' => $this->lookup('quality_grade', 'aftermarket_premium'), 'items' => [['variant_id' => $a, 'qty_delta' => '1']]])->assertStatus(422);
        $this->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $this->showroom, 'reason_id' => $this->reasonId('found'), 'items' => [['variant_id' => $a, 'qty_delta' => '0']]])->assertStatus(422);
    }

    public function test_adjustment_photos_are_private_images_linked_to_the_draft(): void
    {
        [$a] = $this->variants();
        $this->stock($a, '5', '10');
        $this->actingAsRole('storekeeper');
        $adj = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-1']], 'breakage');
        $png = UploadedFile::fake()->createWithContent('crack.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));
        $photo = $this->post("/api/v1/stock-adjustments/{$adj['id']}/photos", ['file' => $png], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->putJson("/api/v1/stock-adjustments/{$adj['id']}", ['items' => [['variant_id' => $a, 'qty_delta' => '-1', 'photo_attachment_id' => $photo['id']]]])->assertOk()->assertJsonPath('data.items.0.photo_attachment_id', $photo['id']);
        $this->get("/api/v1/adjustment-photos/{$photo['id']}")->assertOk()->assertHeader('content-type', 'image/png');
        $php = UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;');
        $this->post("/api/v1/stock-adjustments/{$adj['id']}/photos", ['file' => $php], ['Accept' => 'application/json'])->assertStatus(422);
        $other = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-1']], 'breakage');
        $this->putJson("/api/v1/stock-adjustments/{$other['id']}", ['items' => [['variant_id' => $a, 'qty_delta' => '-1', 'photo_attachment_id' => $photo['id']]]])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get("/api/v1/adjustment-photos/{$photo['id']}", ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_transfers_carry_cost_and_a_short_delivery_leaves_a_draft_adjustment(): void
    {
        [$a, $b] = $this->variants(2);
        $this->stock($a, '10', '100');
        $this->stock($a, '5', '200', $this->godown); // the godown has its own average
        $this->stock($b, '4', '80');
        $this->actingAsRole('storekeeper');
        $t = $this->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $this->showroom, 'to_warehouse_id' => $this->godown, 'items' => [['variant_id' => $a, 'qty_sent' => '4'], ['variant_id' => $b, 'qty_sent' => '4']]])->assertCreated()->json('data');
        $this->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $this->showroom, 'to_warehouse_id' => $this->showroom, 'items' => [['variant_id' => $a, 'qty_sent' => '1']]])->assertStatus(422);
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/receive")->assertStatus(409);
        $this->postJson("/api/v1/stock-transfers/{$t['id']}/send")->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->assertSame('6.000', $this->onHand($a));
        $this->assertSame('0.000', $this->onHand($b));
        $this->assertSame('5.000', $this->onHand($a, $this->godown), 'goods in transit are in neither warehouse');
        $this->putJson("/api/v1/stock-transfers/{$t['id']}", ['note' => 'late'])->assertStatus(409);

        // 4 of A arrive, only 3 of B.
        $received = $this->postJson("/api/v1/stock-transfers/{$t['id']}/receive", ['items' => [['variant_id' => $a, 'qty_received' => '4'], ['variant_id' => $b, 'qty_received' => '3']]])->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame('9.000', $this->onHand($a, $this->godown));
        // godown average: (5 × 200 + 4 × 100) / 9 = 155.5556
        $this->assertSame('155.5556', $this->avgCost($a, $this->godown));
        $this->assertSame('100.0000', $this->avgCost($a), 'the source average is untouched by a transfer out');
        $this->assertSame('80.0000', $this->avgCost($b, $this->godown));
        $draft = StockAdjustment::query()->where('status', 'draft')->latest('id')->firstOrFail();
        $this->assertSame([$this->showroom, '1.000', $b], [$draft->warehouse_id, (string) $draft->items[0]->qty_delta, $draft->items[0]->variant_id]);
        $this->assertStringContainsString($received->json('data.transfer_no'), (string) $draft->note);
        $this->assertSame(1, Notification::query()->where('type', 'transfer_short')->where('role', 'manager')->count());
        $this->assertSame('0.000', $this->onHand($b), 'nothing returned until the draft is posted');
        $this->postJson("/api/v1/stock-adjustments/{$draft->id}/post")->assertOk();
        $this->assertSame('1.000', $this->onHand($b), 'posting the draft puts the missing unit back at the source');
    }

    public function test_transfer_guards_and_cancelling_in_transit_returns_the_goods(): void
    {
        [$a] = $this->variants();
        $this->stock($a, '3', '100');
        $this->actingAsRole('manager');
        $mk = fn (string $q) => $this->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $this->showroom, 'to_warehouse_id' => $this->godown, 'items' => [['variant_id' => $a, 'qty_sent' => $q]]])->assertCreated()->json('data');
        $big = $mk('5');
        $this->postJson("/api/v1/stock-transfers/{$big['id']}/send")->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $ok = $mk('2');
        $this->postJson("/api/v1/stock-transfers/{$ok['id']}/send")->assertOk();
        $this->postJson("/api/v1/stock-transfers/{$ok['id']}/receive", ['items' => [['variant_id' => $a, 'qty_received' => '3']]])->assertStatus(422)->assertJsonPath('code', 'RECEIVED_EXCEEDS_SENT');
        $this->postJson("/api/v1/stock-transfers/{$ok['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('3.000', $this->onHand($a));
        $this->postJson("/api/v1/stock-transfers/{$ok['id']}/send")->assertStatus(409);
    }

    public function test_stock_counts_freeze_the_list_hide_system_qty_when_blind_and_correct_to_what_was_counted(): void
    {
        [$a, $b, $c] = $this->variants(3);
        $this->stock($a, '10', '100');
        $this->stock($b, '5', '100');
        $this->stock($c, '7', '100', $this->godown);
        $this->actingAsRole('storekeeper');
        $count = $this->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->showroom, 'is_blind' => true])->assertCreated()->json('data');
        $this->postJson("/api/v1/stock-counts/{$count['id']}/review")->assertStatus(409);
        $started = $this->postJson("/api/v1/stock-counts/{$count['id']}/start")->assertOk()->json('data');
        $this->assertCount(2, $started['items'], 'only the showroom is on the list');
        $this->assertArrayNotHasKey('system_qty', $started['items'][0], 'a blind count hides the system quantity');

        $this->putJson("/api/v1/stock-counts/{$count['id']}/items", ['items' => [['variant_id' => $a, 'counted_qty' => '8']]])->assertOk();
        $this->postJson("/api/v1/stock-counts/{$count['id']}/review")->assertStatus(422)->assertJsonPath('code', 'INCOMPLETE_COUNT')->assertJsonPath('context.uncounted', 1);
        // A sale-like movement happens while counting: 2 of A leave. The count of 8 already reflects it.
        $this->actingAsRole('manager');
        $adj = $this->adjustment([['variant_id' => $a, 'qty_delta' => '-2']], 'sample_demo');
        $this->postJson("/api/v1/stock-adjustments/{$adj['id']}/post")->assertOk();
        $this->actingAsRole('storekeeper');
        $this->putJson("/api/v1/stock-counts/{$count['id']}/items", ['items' => [['variant_id' => $b, 'counted_qty' => '6']]])->assertOk();
        $this->postJson("/api/v1/stock-counts/{$count['id']}/review")->assertOk()->assertJsonPath('data.status', 'review');
        $review = $this->getJson("/api/v1/stock-counts/{$count['id']}")->assertOk()->json('data.items');
        $this->assertSame(['10.000', '-2.000'], [$review[0]['system_qty'], $review[0]['variance']], 'variances show after counting ends');

        $this->postJson("/api/v1/stock-counts/{$count['id']}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('8.000', $this->onHand($a), 'A was already 8 on file after the sample, so no correction');
        $this->assertSame('6.000', $this->onHand($b));
        $types = array_map(fn ($m) => $m->movement_type, $this->movements($b, $this->showroom));
        $this->assertSame(['opening', 'count_correction'], $types);
        $this->assertCount(2, $this->movements($a, $this->showroom), 'opening and the sample only');
        $this->assertSame('7.000', $this->onHand($c, $this->godown), 'other warehouses are untouched');
        $this->postJson("/api/v1/stock-counts/{$count['id']}/post")->assertStatus(409);
    }

    public function test_category_scoped_counts_and_late_additions(): void
    {
        [$a, $b] = $this->variants(2);
        $this->stock($a, '1', '10');
        $this->stock($b, '1', '10');
        $this->actingAsRole('storekeeper');
        $count = $this->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->showroom, 'scope' => 'category', 'scope_ref' => 999999])->assertCreated()->json('data');
        $this->postJson("/api/v1/stock-counts/{$count['id']}/start")->assertStatus(422)->assertJsonPath('code', 'EMPTY_RECEIPT');
        $this->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->showroom, 'scope' => 'category'])->assertStatus(422);
        $c2 = $this->postJson('/api/v1/stock-counts', ['warehouse_id' => $this->showroom])->assertCreated()->json('data');
        $this->postJson("/api/v1/stock-counts/{$c2['id']}/start")->assertOk();
        [$extra] = $this->variants();
        $this->actingAsRole('storekeeper');
        $added = $this->postJson("/api/v1/stock-counts/{$c2['id']}/items", ['variant_id' => $extra])->assertOk()->json('data.items');
        $this->assertCount(3, $added);
        $this->putJson("/api/v1/stock-counts/{$c2['id']}/items", ['items' => [['variant_id' => 99999999, 'counted_qty' => '1']]])->assertStatus(422);
        $this->postJson("/api/v1/stock-counts/{$c2['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function csv(array $rows): UploadedFile
    {
        $out = implode(',', ['sku', 'warehouse', 'qty', 'unit_cost'])."\n";
        foreach ($rows as $r) {
            $out .= implode(',', $r)."\n";
        }

        return UploadedFile::fake()->createWithContent('opening.csv', "\xEF\xBB\xBF".$out);
    }

    public function test_opening_stock_import_validates_every_row_then_loads_stock_once(): void
    {
        [$a, $b] = $this->variants(2);
        $skuA = ProductVariant::query()->find($a)->sku;
        $skuB = ProductVariant::query()->find($b)->sku;
        $this->actingAsRole('manager');
        $bad = $this->csv([[$skuA, 'SHOW', '10', '250'], [$skuA, 'show', '1', '250'], ['NOPE-1', 'SHOW', '1', '1'], [$skuB, 'MOON', '1', '1'], [$skuB, 'SHOW', '0', '5'], [$skuB, 'SHOW', '3', '0']]);
        $res = $this->post('/api/v1/imports/dry-run', ['kind' => 'opening-stock', 'file' => $bad], ['Accept' => 'application/json'])->assertOk();
        $codes = collect($res->json('data.summary.errors'))->map(fn ($e) => $e['row'].':'.$e['code'])->all();
        $this->assertSame(['3:DUPLICATE_IN_FILE', '4:UNKNOWN_SKU', '5:UNKNOWN_WAREHOUSE', '6:INVALID_NUMBER', '7:COST_REQUIRED', '7:DUPLICATE_IN_FILE'], $codes);
        $this->postJson('/api/v1/imports/commit', ['kind' => 'opening-stock', 'token' => $res->json('data.token')])->assertStatus(422)->assertJsonPath('code', 'IMPORT_HAS_ERRORS');
        $this->assertSame('0.000', $this->onHand($a));

        $good = $this->post('/api/v1/imports/dry-run', ['kind' => 'opening-stock', 'file' => $this->csv([[$skuA, 'SHOW', '10', '250.5'], [$skuA, 'GODOWN', '4', '240'], [$skuB, 'SHOW', '2.5', '99']])], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(3, $good->json('data.summary.valid_rows'));
        $this->postJson('/api/v1/imports/commit', ['kind' => 'opening-stock', 'token' => $good->json('data.token')])->assertOk()->assertJsonPath('data.created', 3);
        $this->assertSame(['10.000', '250.5000'], [$this->onHand($a), $this->avgCost($a)]);
        $this->assertSame(['4.000', '240.0000'], [$this->onHand($a, $this->godown), $this->avgCost($a, $this->godown)]);
        $this->assertSame('2.500', $this->onHand($b));
        $this->assertSame('opening', $this->movements($a)[0]->movement_type);

        // Opening a variant that already has history in that warehouse is refused: use a receipt or an adjustment.
        $again = $this->post('/api/v1/imports/dry-run', ['kind' => 'opening-stock', 'file' => $this->csv([[$skuA, 'SHOW', '1', '1']])], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('ALREADY_STOCKED', $again->json('data.summary.errors.0.code'));

        $this->get('/api/v1/imports/templates/opening-stock')->assertOk();
        $this->actingAsRole('storekeeper');
        $this->get('/api/v1/imports/templates/opening-stock')->assertStatus(403);
    }
}
