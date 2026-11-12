<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleModel;
use App\Services\Catalog\VariantSupport;

class ProductVariantTest extends CatalogTestCase
{
    public function test_product_is_created_as_draft_and_validated(): void
    {
        $this->actingAsRole('manager');
        $res = $this->postJson('/api/v1/products', $this->productPayload())->assertCreated();
        $res->assertJsonPath('data.is_active', false)->assertJsonPath('data.code', 'SM-TOY-AXIO')->assertJsonPath('data.tags', ['wing mirror', 'লুকিং গ্লাস']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'entity_table' => 'products']);
        // maintained lowercase search text: names, code, tags (Bangla tags stay searchable)
        $this->assertSame('side mirror – toyota axio nze141 sm-toy-axio wing mirror লুকিং গ্লাস', Product::query()->value('search_text'));

        $this->postJson('/api/v1/products', $this->productPayload())->assertStatus(409)->assertJsonPath('code', 'CODE_DUPLICATE')->assertJsonPath('context.existing_product_id', $res->json('data.id'));
        $this->postJson('/api/v1/products', $this->productPayload(['code' => 'bad code']))->assertStatus(422)->assertJsonPath('code', 'VALIDATION');
        $this->postJson('/api/v1/products', ['code' => 'X1'])->assertStatus(422)->assertJsonStructure(['fields' => ['name_en', 'mirror_type', 'category_id', 'quality_grade_id', 'unit_id']]);
    }

    public function test_variant_gets_generated_sku_size_label_name_and_signature(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $glass = $this->lookup('glass_type', 'convex');
        $v = $this->makeVariant($pid, ['glass_type_id' => $glass, 'heated' => true, 'color_finish_id' => $this->lookup('color_finish', 'black')]);
        $this->assertSame('SM-TOY-AXIO-R-180X110', $v['sku']);
        $this->assertSame('180 × 110 mm', $v['size_label']);
        $this->assertSame('RH · Electric · Heated · Convex · Black · 180 × 110 mm', $v['variant_name']);
        $this->assertSame('12500.00', $v['retail_price']);
        $row = ProductVariant::query()->findOrFail($v['id']);
        $this->assertSame(40, strlen($row->signature));
        $this->assertStringContainsString('sm-toy-axio-r-180x110', $row->search_text);
        $this->assertStringContainsString('convex', $row->search_text);
    }

    public function test_duplicate_variant_is_rejected_with_a_pointer_to_the_existing_one(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $first = $this->makeVariant($pid);
        $res = $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['sku' => 'OTHER-SKU']))->assertStatus(409);
        $res->assertJsonPath('code', 'VARIANT_DUPLICATE')->assertJsonPath('context.existing_variant_id', $first['id'])->assertJsonPath('context.existing_sku', 'SM-TOY-AXIO-R-180X110');
        // a different position, size or feature is a different variant
        $this->makeVariant($pid, ['position' => 'left']);
        $this->makeVariant($pid, ['size_width_mm' => 200]);
        $this->makeVariant($pid, ['heated' => true]);
        $this->assertSame(4, ProductVariant::query()->count());
    }

    public function test_sku_and_barcode_are_globally_unique_including_aliases_and_deleted_variants(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $a = $this->makeVariant($pid, ['sku' => 'abc-1', 'barcode' => '8801234567890', 'barcode_aliases' => ['ALIAS-1']]);
        $this->assertSame('ABC-1', $a['sku']);

        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['position' => 'left', 'sku' => 'ABC-1']))->assertStatus(409)->assertJsonPath('code', 'SKU_DUPLICATE');
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['position' => 'left', 'barcode' => '8801234567890']))->assertStatus(409)->assertJsonPath('code', 'BARCODE_DUPLICATE');
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['position' => 'left', 'barcode' => 'ALIAS-1']))->assertStatus(409)->assertJsonPath('code', 'BARCODE_DUPLICATE');
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['position' => 'left', 'barcode' => '111', 'barcode_aliases' => ['8801234567890']]))->assertStatus(409)->assertJsonPath('code', 'BARCODE_DUPLICATE');

        // deleted variants keep their SKU and barcode reserved, but free the variant slot
        $this->deleteJson("/api/v1/variants/{$a['id']}")->assertOk();
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['position' => 'left', 'sku' => 'ABC-1']))->assertStatus(409)->assertJsonPath('code', 'SKU_DUPLICATE');
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['sku' => 'NEW-1']))->assertCreated();
    }

    public function test_prices_cannot_be_negative_and_floor_cannot_exceed_retail(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['retail_price' => '-1']))->assertStatus(422)->assertJsonStructure(['fields' => ['retail_price']]);
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['wholesale_price' => '-5']))->assertStatus(422)->assertJsonStructure(['fields' => ['wholesale_price']]);
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['min_price' => '13000']))->assertStatus(422)->assertJsonStructure(['fields' => ['min_price']]);
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['retail_price' => '10.555']))->assertStatus(422);
        $this->postJson("/api/v1/products/{$pid}/variants", array_diff_key($this->variantPayload(), ['retail_price' => 1]))->assertStatus(422);
    }

    public function test_price_changes_are_audited_and_other_edits_are_not_price_changes(): void
    {
        $this->actingAsRole('manager');
        $v = $this->makeVariant($this->makeProduct());
        $this->putJson("/api/v1/variants/{$v['id']}", ['internal_note' => 'hello'])->assertOk();
        $this->assertSame(0, AuditLog::query()->where('action', 'price_change')->count());

        $this->putJson("/api/v1/variants/{$v['id']}", ['retail_price' => '13000.00', 'wholesale_price' => '11000'])->assertOk()->assertJsonPath('data.retail_price', '13000.00');
        $log = AuditLog::query()->where('action', 'price_change')->firstOrFail();
        $this->assertSame('product_variants', $log->entity_table);
        $this->assertSame($v['id'], $log->entity_id);
        $this->assertSame(['retail_price' => '12500.00', 'wholesale_price' => null], $log->before);
        $this->assertEquals(['retail_price' => '13000.00', 'wholesale_price' => '11000.00'], $log->after);
    }

    public function test_updating_the_variant_to_collide_with_another_is_blocked(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $this->makeVariant($pid);
        $left = $this->makeVariant($pid, ['position' => 'left']);
        $this->putJson("/api/v1/variants/{$left['id']}", ['position' => 'right'])->assertStatus(409)->assertJsonPath('code', 'VARIANT_DUPLICATE');
        $this->putJson("/api/v1/variants/{$left['id']}", ['retail_price' => '9999'])->assertOk(); // unrelated edit of itself is fine
    }

    public function test_clone_needs_a_difference_and_gets_a_fresh_sku_without_barcode(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $v = $this->makeVariant($pid, ['barcode' => '5551234567890']);
        $this->postJson("/api/v1/variants/{$v['id']}/clone", [])->assertStatus(409)->assertJsonPath('code', 'VARIANT_DUPLICATE');
        $clone = $this->postJson("/api/v1/variants/{$v['id']}/clone", ['position' => 'left'])->assertCreated()->json('data');
        $this->assertSame('SM-TOY-AXIO-L-180X110', $clone['sku']);
        $this->assertNull($clone['barcode']);
        $this->assertSame('left', $clone['position']);
    }

    public function test_generated_barcode_is_a_valid_unique_ean13(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $a = $this->makeVariant($pid);
        $b = $this->makeVariant($pid, ['position' => 'left']);
        $codeA = $this->postJson("/api/v1/variants/{$a['id']}/barcode")->assertOk()->json('data.barcode');
        $codeB = $this->postJson("/api/v1/variants/{$b['id']}/barcode")->assertOk()->json('data.barcode');
        $this->assertTrue(VariantSupport::isValidEan13($codeA));
        $this->assertTrue(VariantSupport::isValidEan13($codeB));
        $this->assertNotSame($codeA, $codeB);
        $this->assertStringStartsWith('200', $codeA);
        // idempotent
        $this->assertSame($codeA, $this->postJson("/api/v1/variants/{$a['id']}/barcode")->json('data.barcode'));
        $withAuto = $this->makeVariant($pid, ['position' => 'interior', 'generate_barcode' => true]);
        $this->assertTrue(VariantSupport::isValidEan13($withAuto['barcode']));
    }

    public function test_publish_needs_an_active_variant_and_list_search_finds_oem_numbers(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $this->postJson("/api/v1/products/{$pid}/publish")->assertStatus(422)->assertJsonPath('code', 'PRODUCT_INCOMPLETE');
        $v = $this->makeVariant($pid);
        $this->postJson("/api/v1/products/{$pid}/publish")->assertOk()->assertJsonPath('data.is_active', true);

        $this->postJson("/api/v1/variants/{$v['id']}/cross-references", ['number' => '87910-02B30', 'brand' => 'Toyota'])->assertCreated()->assertJsonPath('data.number_normalized', '8791002B30');
        $this->postJson("/api/v1/variants/{$v['id']}/cross-references", ['number' => '8791002b30'])->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
        $this->getJson('/api/v1/products?search=8791002b30')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pid);
        $this->getJson('/api/v1/products?search=87910 02b30')->assertOk()->assertJsonPath('meta.total', 0); // spaces are not normalized in the list search
        $this->getJson('/api/v1/variants/search?q=87910-02B30')->assertOk()->assertJsonPath('data.0.id', $v['id']);
        $this->getJson('/api/v1/variants/search?q=8791002B30')->assertOk()->assertJsonPath('data.0.id', $v['id']);
        $this->getJson('/api/v1/variants/search?q=nomatchzz')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_clone_product_copies_variants_and_fitments_but_not_barcodes_or_images(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $v = $this->makeVariant($pid, ['barcode' => '4400000000011']);
        $model = VehicleModel::query()->where('name', 'Axio')->value('id');
        $this->postJson("/api/v1/variants/{$v['id']}/fitments", ['vehicle_model_id' => $model, 'year_from' => 2012, 'year_to' => 2018])->assertCreated();
        $copy = $this->postJson("/api/v1/products/{$pid}/clone", [])->assertCreated()->json('data');
        $this->assertSame('SM-TOY-AXIO-COPY', $copy['code']);
        $this->assertFalse($copy['is_active']);
        $this->assertCount(1, $copy['variants']);
        $this->assertNull($copy['variants'][0]['barcode']);
        $this->assertCount(1, $copy['variants'][0]['fitments']);
        $this->assertSame('SM-TOY-AXIO-COPY-R-180X110', $copy['variants'][0]['sku']);
    }

    public function test_deleting_a_product_soft_deletes_variants(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $this->makeVariant($pid);
        $this->deleteJson("/api/v1/products/{$pid}")->assertOk();
        $this->assertSame(0, ProductVariant::query()->count());
        $this->assertSame(1, ProductVariant::onlyTrashed()->count());
        $this->getJson("/api/v1/products/{$pid}")->assertStatus(404);
    }

    public function test_bundles_hold_components_without_nesting_or_self_reference(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $left = $this->makeVariant($pid, ['position' => 'left']);
        $right = $this->makeVariant($pid, ['position' => 'right']);
        $pair = $this->makeVariant($pid, ['position' => 'pair', 'is_bundle' => true]);
        $other = $this->makeVariant($pid, ['position' => 'universal']);

        $this->postJson("/api/v1/variants/{$left['id']}/bundle-items", ['component_variant_id' => $right['id'], 'qty' => 1])->assertStatus(422)->assertJsonPath('code', 'BUNDLE_NOT_ENABLED');
        $res = $this->postJson("/api/v1/variants/{$pair['id']}/bundle-items", ['component_variant_id' => $left['id'], 'qty' => 1])->assertCreated();
        $this->postJson("/api/v1/variants/{$pair['id']}/bundle-items", ['component_variant_id' => $right['id'], 'qty' => '2.5'])->assertCreated();
        $this->assertCount(2, $res->json('data') ? $this->getJson("/api/v1/variants/{$pair['id']}")->json('data.bundle_items') : []);
        $this->postJson("/api/v1/variants/{$pair['id']}/bundle-items", ['component_variant_id' => $left['id'], 'qty' => 1])->assertStatus(409);
        $this->postJson("/api/v1/variants/{$pair['id']}/bundle-items", ['component_variant_id' => $pair['id'], 'qty' => 1])->assertStatus(422)->assertJsonPath('code', 'BUNDLE_SELF');
        $this->postJson("/api/v1/variants/{$pair['id']}/bundle-items", ['component_variant_id' => $left['id'], 'qty' => 0])->assertStatus(422);

        // no nesting: a bundle cannot be a component, and a component cannot become a bundle
        $second = $this->makeVariant($pid, ['position' => 'interior', 'is_bundle' => true]);
        $this->postJson("/api/v1/variants/{$second['id']}/bundle-items", ['component_variant_id' => $pair['id'], 'qty' => 1])->assertStatus(422)->assertJsonPath('code', 'NESTED_BUNDLE');
        $this->putJson("/api/v1/variants/{$left['id']}", ['is_bundle' => true])->assertStatus(422)->assertJsonPath('code', 'NESTED_BUNDLE');
        $this->putJson("/api/v1/variants/{$pair['id']}", ['is_bundle' => false])->assertStatus(422)->assertJsonPath('code', 'BUNDLE_HAS_ITEMS');
        $this->putJson("/api/v1/variants/{$other['id']}", ['is_bundle' => true])->assertOk();

        $items = $this->getJson("/api/v1/variants/{$pair['id']}")->json('data.bundle_items');
        $this->assertSame('SM-TOY-AXIO-L-180X110', $items[0]['sku']);
        $this->deleteJson("/api/v1/bundle-items/{$items[0]['id']}")->assertOk()->assertJsonCount(1, 'data.bundle_items');
    }
}
