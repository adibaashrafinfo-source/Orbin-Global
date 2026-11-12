<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleFitment;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Http\UploadedFile;

class MatrixImagesFitmentTest extends CatalogTestCase
{
    protected function tearDown(): void
    {
        // uploaded test images are real files in public/uploads/products
        foreach (glob(public_path('uploads/products/*/*/*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function spec(array $over = []): array
    {
        return array_merge([
            'positions' => ['left', 'right'],
            'sizes' => [['width_mm' => 180, 'height_mm' => 110], ['width_mm' => 200, 'height_mm' => 120], ['label' => 'Universal 7x4']],
            'defaults' => ['retail_price' => '5000', 'adjustment' => 'manual'],
        ], $over);
    }

    public function test_matrix_dry_run_then_generate_creates_six_variants_with_automatic_skus(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $plan = $this->postJson("/api/v1/products/{$pid}/variants/matrix", $this->spec() + ['dry_run' => true])->assertOk()->json('data');
        $this->assertCount(6, $plan['plan']);
        $this->assertSame(0, ProductVariant::query()->count(), 'dry run must not write');
        $this->assertSame(0, $plan['skipped']);

        $res = $this->postJson("/api/v1/products/{$pid}/variants/matrix", $this->spec())->assertCreated()->json('data');
        $this->assertCount(6, $res['created']);
        $skus = ProductVariant::query()->orderBy('id')->pluck('sku')->all();
        $this->assertSame(['SM-TOY-AXIO-L-180X110', 'SM-TOY-AXIO-L-200X120', 'SM-TOY-AXIO-L-UNIVERSAL7X4', 'SM-TOY-AXIO-R-180X110', 'SM-TOY-AXIO-R-200X120', 'SM-TOY-AXIO-R-UNIVERSAL7X4'], $skus);
        $this->assertSame('5000.00', ProductVariant::query()->first()->retail_price);
        $this->assertSame('Universal 7x4', ProductVariant::query()->where('sku', 'SM-TOY-AXIO-R-UNIVERSAL7X4')->value('size_label'));
    }

    public function test_matrix_skips_existing_combinations_instead_of_overwriting(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $existing = $this->makeVariant($pid, ['position' => 'left', 'size_width_mm' => 180, 'size_height_mm' => 110, 'adjustment' => 'manual', 'retail_price' => '9999']);
        $res = $this->postJson("/api/v1/products/{$pid}/variants/matrix", $this->spec())->assertCreated()->json('data');
        $this->assertCount(5, $res['created']);
        $this->assertSame(1, $res['skipped']);
        $this->assertSame('9999.00', ProductVariant::query()->find($existing['id'])->retail_price); // untouched
    }

    public function test_matrix_crosses_finishes_and_feature_sets_and_reports_internal_repeats(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $spec = $this->spec([
            'positions' => ['right'], 'sizes' => [['width_mm' => 180, 'height_mm' => 110]],
            'color_finish_ids' => [$this->lookup('color_finish', 'black'), $this->lookup('color_finish', 'chrome')],
            'feature_sets' => [[], ['heated' => true], ['heated' => true]], // the last set repeats the previous one
        ]);
        $plan = $this->postJson("/api/v1/products/{$pid}/variants/matrix", $spec + ['dry_run' => true])->assertOk()->json('data');
        $this->assertCount(6, $plan['plan']);
        $this->assertSame(2, $plan['skipped']);
        $res = $this->postJson("/api/v1/products/{$pid}/variants/matrix", $spec)->assertCreated()->json('data');
        $this->assertCount(4, $res['created']);
        $this->assertContains('RH · Manual · Heated · Black · 180 × 110 mm', ProductVariant::query()->pluck('variant_name')->all());
    }

    public function test_matrix_guards(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $sizes = array_map(fn ($i) => ['width_mm' => 100 + $i, 'height_mm' => 50], range(1, 40));
        $finishes = [$this->lookup('color_finish', 'black'), $this->lookup('color_finish', 'chrome')];
        $this->postJson("/api/v1/products/{$pid}/variants/matrix", $this->spec(['positions' => ['left', 'right', 'pair', 'interior', 'universal'], 'sizes' => $sizes, 'color_finish_ids' => $finishes]))
            ->assertStatus(422)->assertJsonPath('code', 'MATRIX_TOO_LARGE')->assertJsonPath('context.combinations', 400)->assertJsonPath('context.max', 200);
        $this->assertSame(0, ProductVariant::query()->count());
    }

    public function test_images_upload_resize_thumbnail_primary_and_limit(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $big = UploadedFile::fake()->image('big.jpg', 3000, 1500);
        $res = $this->post("/api/v1/products/{$pid}/images", ['file' => $big, 'label' => 'front', 'alt_text' => 'Front view'], ['Accept' => 'application/json'])->assertCreated();
        $first = $res->json('data');
        $this->assertTrue($first['is_primary']);
        $this->assertSame('front', $first['label']);
        [$w, $h] = getimagesize(public_path(ltrim($first['url'], '/')));
        $this->assertSame([2000, 1000], [$w, $h], 'long edge is capped at 2000 px');
        [$tw, $th] = getimagesize(public_path(ltrim($first['thumb_url'], '/')));
        $this->assertSame([400, 200], [$tw, $th]);
        $this->assertMatchesRegularExpression('#^/uploads/products/\d{4}/\d{2}/[a-z0-9]{24}\.jpg$#', $first['url']);
        $this->assertSame($first['thumb_url'], $this->getJson("/api/v1/products/{$pid}")->json('data.primary_image_url'));

        // small images are not enlarged; png and webp are accepted; the second image is not primary
        $png = $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image('small.png', 300, 200)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertFalse($png['is_primary']);
        $this->assertSame([300, 200], array_slice(getimagesize(public_path(ltrim($png['url'], '/'))), 0, 2));
        $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image('w.webp', 500, 500)], ['Accept' => 'application/json'])->assertCreated();

        // the 8-image limit applies per product and per variant scope
        for ($i = 0; $i < 5; $i++) {
            $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image("x{$i}.jpg", 100, 100)], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image('ninth.jpg', 100, 100)], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'IMAGE_LIMIT');
        $v = $this->makeVariant($pid);
        $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image('v.jpg', 100, 100), 'variant_id' => $v['id']], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_images_reject_fakes_reorder_set_primary_and_delete_promotes_next(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo 1;')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/v1/products/{$pid}/images", [], ['Accept' => 'application/json'])->assertStatus(422);

        $a = $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image('a.jpg', 200, 200)], ['Accept' => 'application/json'])->json('data');
        $b = $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image('b.jpg', 200, 200)], ['Accept' => 'application/json'])->json('data');
        $c = $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image('c.jpg', 200, 200)], ['Accept' => 'application/json'])->json('data');
        $this->putJson("/api/v1/products/{$pid}/images/reorder", ['ids' => [$c['id'], $a['id'], $b['id']]])->assertOk();
        $this->assertSame([$c['id'], $a['id'], $b['id']], ProductImage::query()->where('product_id', $pid)->orderBy('sort_order')->pluck('id')->all());
        $this->putJson("/api/v1/images/{$b['id']}", ['label' => 'connector', 'alt_text' => 'plug'])->assertOk()->assertJsonPath('data.label', 'connector');
        $this->putJson("/api/v1/images/{$b['id']}", ['label' => 'nonsense'])->assertStatus(422);

        $this->postJson("/api/v1/images/{$b['id']}/primary")->assertOk();
        $this->assertSame([$b['id']], ProductImage::query()->where('product_id', $pid)->where('is_primary', true)->pluck('id')->all());
        $fileB = public_path(ltrim($b['url'], '/'));
        $this->assertFileExists($fileB);
        $this->deleteJson("/api/v1/images/{$b['id']}")->assertOk();
        $this->assertFileDoesNotExist($fileB);
        $this->assertSame(1, ProductImage::query()->where('product_id', $pid)->where('is_primary', true)->count(), 'next image is promoted');
        foreach ([$a, $c] as $img) {
            $this->deleteJson("/api/v1/images/{$img['id']}")->assertOk();
        }
        $this->assertNull($this->getJson("/api/v1/products/{$pid}")->json('data.primary_image_url'));
    }

    public function test_fitments_duplicates_years_apply_to_all_and_copy(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $r = $this->makeVariant($pid);
        $l = $this->makeVariant($pid, ['position' => 'left']);
        $axio = VehicleModel::query()->where('name', 'Axio')->value('id');
        $allion = VehicleModel::query()->where('name', 'Allion')->value('id');
        $premio = VehicleModel::query()->where('name', 'Premio')->value('id');

        $this->postJson("/api/v1/variants/{$r['id']}/fitments", ['vehicle_model_id' => $axio, 'year_from' => 2012, 'year_to' => 2018, 'trim' => 'Hybrid', 'note' => 'with BSM only'])->assertCreated();
        $this->postJson("/api/v1/variants/{$r['id']}/fitments", ['vehicle_model_id' => $axio, 'year_from' => 2012, 'year_to' => 2018, 'trim' => ' hybrid '])->assertStatus(409)->assertJsonPath('code', 'FITMENT_DUPLICATE');
        $this->postJson("/api/v1/variants/{$r['id']}/fitments", ['vehicle_model_id' => $axio, 'year_from' => 2012, 'year_to' => 2018])->assertCreated(); // no trim is a different row
        $this->postJson("/api/v1/variants/{$r['id']}/fitments", ['vehicle_model_id' => $axio, 'year_from' => 2019])->assertCreated(); // open ended
        $this->postJson("/api/v1/variants/{$r['id']}/fitments", ['vehicle_model_id' => $axio, 'year_from' => 2019])->assertStatus(409);
        $this->postJson("/api/v1/variants/{$r['id']}/fitments", ['vehicle_model_id' => $allion, 'year_from' => 2020, 'year_to' => 2010])->assertStatus(422);
        $this->postJson("/api/v1/variants/{$r['id']}/fitments", ['vehicle_model_id' => 999999, 'year_from' => 2020])->assertStatus(422);

        $this->postJson("/api/v1/variants/{$r['id']}/fitments", ['vehicle_model_id' => $allion, 'year_from' => 2010, 'year_to' => 2015, 'apply_to_all_variants' => true])->assertCreated()->assertJsonPath('data.created', 2);
        $this->assertSame(1, VehicleFitment::query()->where('variant_id', $l['id'])->count());
        $res = $this->postJson("/api/v1/variants/{$r['id']}/fitments/copy", ['target_variant_ids' => [$l['id']]])->assertOk()->json('data');
        $this->assertSame(['created' => 3, 'skipped' => 1], $res); // the Allion row already exists on the left variant

        $fit = $this->getJson("/api/v1/variants/{$r['id']}")->json('data.fitments');
        $this->assertCount(4, $fit);
        $this->assertSame(['Toyota', 'Axio'], [$fit[0]['make'], $fit[0]['model']]);
        $this->deleteJson("/api/v1/fitments/{$fit[0]['id']}")->assertOk();
        $this->assertCount(3, $this->getJson("/api/v1/variants/{$r['id']}")->json('data.fitments'));
        $this->getJson("/api/v1/products?filter[model_id]={$premio}")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/products?filter[model_id]={$allion}")->assertOk()->assertJsonPath('meta.total', 1);
        $makeId = VehicleMake::query()->where('name', 'Toyota')->value('id');
        $this->getJson("/api/v1/products?filter[make_id]={$makeId}&filter[position]=left")->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_a_t_gate_mirror_with_six_variants_four_images_and_three_fitments(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $this->postJson("/api/v1/products/{$pid}/variants/matrix", $this->spec())->assertCreated();
        foreach (['front', 'back', 'connector', 'package'] as $label) {
            $this->post("/api/v1/products/{$pid}/images", ['file' => UploadedFile::fake()->image("{$label}.jpg", 800, 600), 'label' => $label], ['Accept' => 'application/json'])->assertCreated();
        }
        $variant = ProductVariant::query()->where('sku', 'SM-TOY-AXIO-R-180X110')->firstOrFail();
        foreach (['Axio', 'Allion', 'Premio'] as $name) {
            $model = VehicleModel::query()->where('name', $name)->value('id');
            $this->postJson("/api/v1/variants/{$variant->id}/fitments", ['vehicle_model_id' => $model, 'year_from' => 2010, 'year_to' => 2020])->assertCreated();
        }
        $this->postJson("/api/v1/products/{$pid}/publish")->assertOk();
        $detail = $this->getJson("/api/v1/products/{$pid}")->assertOk()->json('data');
        $this->assertCount(6, $detail['variants']);
        $this->assertCount(4, $detail['images']);
        $this->assertSame(3, collect($detail['variants'])->firstWhere('id', $variant->id)['fitments'] ? count(collect($detail['variants'])->firstWhere('id', $variant->id)['fitments']) : 0);
        $this->assertTrue($detail['is_active']);
        $this->assertSame(['label' => 'front'], ['label' => $detail['images'][0]['label']]);
    }

    public function test_bulk_price_preview_commit_rounding_audit_and_guards(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $a = $this->makeVariant($pid, ['retail_price' => '12500', 'wholesale_price' => '11000', 'min_price' => '10000']);
        $b = $this->makeVariant($pid, ['position' => 'left', 'retail_price' => '333.33', 'dealer_price' => '300']);
        $spec = ['mode' => 'percent', 'amount' => '10', 'columns' => ['retail_price', 'wholesale_price'], 'filter' => ['product_ids' => [$pid]]];

        $prev = $this->postJson('/api/v1/variants/bulk-price', $spec + ['dry_run' => true])->assertOk()->json('data');
        $this->assertSame(2, $prev['count']);
        $this->assertSame(2, $prev['changed']);
        $this->assertSame('12500.00', ProductVariant::query()->find($a['id'])->retail_price, 'preview must not write');
        $this->assertSame(0, AuditLog::query()->where('action', 'price_change')->count());

        $this->postJson('/api/v1/variants/bulk-price', $spec)->assertOk();
        $this->assertSame('13750.00', ProductVariant::query()->find($a['id'])->retail_price);
        $this->assertSame('12100.00', ProductVariant::query()->find($a['id'])->wholesale_price);
        $this->assertSame('366.66', ProductVariant::query()->find($b['id'])->retail_price); // 333.33 × 1.10 = 366.663 → 366.66
        $this->assertSame(2, AuditLog::query()->where('action', 'price_change')->count());
        $log = AuditLog::query()->where('action', 'price_change')->where('entity_id', $a['id'])->firstOrFail();
        $this->assertSame('12500.00', $log->before['retail_price']);
        $this->assertSame('13750.00', $log->after['retail_price']);

        $this->postJson('/api/v1/variants/bulk-price', ['mode' => 'percent', 'amount' => '-5.5', 'columns' => ['retail_price'], 'filter' => ['variant_ids' => [$b['id']]]])->assertOk();
        // 366.66 × 5.5 % = 20.1663 → 20.17 (half-up at paisa) → 346.49
        $this->assertSame('346.49', ProductVariant::query()->find($b['id'])->retail_price);
        $this->postJson('/api/v1/variants/bulk-price', ['mode' => 'fixed', 'amount' => '-500', 'columns' => ['retail_price'], 'filter' => ['variant_ids' => [$b['id']]]])->assertStatus(422)->assertJsonPath('code', 'PRICE_NEGATIVE');
        $this->postJson('/api/v1/variants/bulk-price', ['mode' => 'fixed', 'amount' => '100', 'columns' => ['retail_price'], 'filter' => ['product_ids' => [999999]]])->assertStatus(422)->assertJsonPath('code', 'NO_MATCH');
        $this->postJson('/api/v1/variants/bulk-price', ['mode' => 'fixed', 'amount' => '1e3', 'columns' => ['retail_price'], 'filter' => []])->assertStatus(422);
        $this->postJson('/api/v1/variants/bulk-price', ['mode' => 'fixed', 'amount' => '5', 'columns' => ['last_cost'], 'filter' => []])->assertStatus(422);

        // the floor price never ends above retail
        $this->postJson('/api/v1/variants/bulk-price', ['mode' => 'fixed', 'amount' => '-4000', 'columns' => ['retail_price'], 'filter' => ['variant_ids' => [$a['id']]]])->assertOk();
        $row = ProductVariant::query()->find($a['id']);
        $this->assertSame('9750.00', $row->retail_price);
        $this->assertSame('9750.00', $row->min_price);
    }

    public function test_cost_is_omitted_from_responses_without_view_cost(): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $v = $this->makeVariant($pid);
        ProductVariant::query()->whereKey($v['id'])->update(['last_cost' => '8123.50']);
        $this->assertSame('8123.50', $this->getJson("/api/v1/variants/{$v['id']}")->json('data.last_cost'));
        $this->assertSame('8123.50', $this->getJson("/api/v1/products/{$pid}")->json('data.variants.0.last_cost'));

        foreach (['cashier', 'storekeeper'] as $role) {
            $this->actingAsRole($role);
            $one = $this->getJson("/api/v1/variants/{$v['id']}")->assertOk()->json('data');
            $this->assertArrayNotHasKey('last_cost', $one, "{$role} must not see cost");
            $this->assertStringNotContainsString('8123', $this->getJson("/api/v1/products/{$pid}")->getContent());
            $this->assertStringNotContainsString('8123', $this->getJson('/api/v1/variants/search?q=axio')->getContent());
        }
        $this->actingAsRole('accountant');
        $this->assertSame('8123.50', $this->getJson("/api/v1/variants/{$v['id']}")->json('data.last_cost'));
    }

    public function test_price_columns_need_pricing_manage_even_with_products_manage(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson('/api/v1/role-permissions/cashier', ['permissions' => ['products.manage' => true]])->assertOk(); // can edit products, but may not touch prices
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $v = $this->makeVariant($pid);
        $this->actingAsRole('cashier');
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['position' => 'left']))->assertStatus(403)->assertJsonPath('code', 'PRICING_FORBIDDEN');
        $this->putJson("/api/v1/variants/{$v['id']}", ['retail_price' => '1.00'])->assertStatus(403)->assertJsonPath('code', 'PRICING_FORBIDDEN');
        $this->putJson("/api/v1/variants/{$v['id']}", ['retail_price' => '12500', 'internal_note' => 'unchanged price is fine'])->assertOk();
        $this->putJson("/api/v1/variants/{$v['id']}", ['internal_note' => 'note only'])->assertOk();
        $this->postJson('/api/v1/variants/bulk-price', ['mode' => 'fixed', 'amount' => '5', 'columns' => ['retail_price'], 'filter' => ['product_ids' => [$pid]]])->assertStatus(403);
    }
}
