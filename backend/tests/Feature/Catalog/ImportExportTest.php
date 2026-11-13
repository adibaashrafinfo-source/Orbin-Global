<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleFitment;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\ImportService;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

class ImportExportTest extends CatalogTestCase
{
    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/private/imports/*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @param array<int, array<int, string>> $rows */
    private function csv(array $rows): UploadedFile
    {
        $out = '';
        foreach ($rows as $r) {
            $out .= implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $r))."\n";
        }

        return UploadedFile::fake()->createWithContent('upload.csv', "\xEF\xBB\xBF".$out);
    }

    private function productHeader(): array
    {
        return ImportService::HEADERS['products'];
    }

    /** Build a products row from named values; everything else stays empty. */
    private function prow(array $v): array
    {
        return array_map(fn ($h) => $v[$h] ?? '', $this->productHeader());
    }

    private function dry(string $kind, UploadedFile $file)
    {
        return $this->post('/api/v1/imports/dry-run', ['kind' => $kind, 'file' => $file], ['Accept' => 'application/json']);
    }

    private function good(): array
    {
        $base = ['product_code' => 'IM-AXIO', 'name_en' => 'Imported Axio Mirror', 'mirror_type' => 'side_mirror', 'category' => 'Side Mirrors', 'quality_grade' => 'Aftermarket premium', 'origin_country' => 'Taiwan', 'unit' => 'pcs', 'tax_rate_code' => 'VAT0', 'warranty_months' => '6', 'tags' => 'wing mirror;side glass'];

        return [
            $this->productHeader(),
            $this->prow($base + ['position' => 'right', 'width_mm' => '180', 'height_mm' => '110', 'glass_type' => 'Convex', 'adjustment' => 'electric', 'heated' => 'yes', 'retail_price' => '12500', 'wholesale_price' => '11000', 'min_price' => '9500', 'oem_numbers' => '87910-02B30;87910-02B31', 'barcode' => '8801112223334']),
            $this->prow(['product_code' => 'IM-AXIO', 'position' => 'left', 'width_mm' => '180', 'height_mm' => '110', 'retail_price' => '12500', 'adjustment' => 'electric', 'glass_type' => 'Convex', 'heated' => 'yes']),
            $this->prow(['product_code' => 'IM-AXIO', 'position' => 'left', 'size_label' => 'Universal', 'retail_price' => '9000']),
            $this->prow(['product_code' => 'IM-CIVIC', 'name_en' => 'Imported Civic Interior Mirror', 'mirror_type' => 'interior_mirror', 'category' => 'Interior Rear-view Mirrors', 'quality_grade' => 'Genuine OEM', 'unit' => 'pcs', 'position' => 'interior', 'retail_price' => '3500']),
        ];
    }

    public function test_templates_download_as_csv_and_xlsx_with_the_expected_headers(): void
    {
        $this->actingAsRole('manager');
        foreach (ImportService::KINDS as $kind) {
            $csv = $this->get("/api/v1/imports/templates/{$kind}")->assertOk();
            $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
            $this->assertStringStartsWith(implode(',', ImportService::HEADERS[$kind]), ltrim($csv->getContent(), "\xEF\xBB\xBF"));
            $xlsx = $this->get("/api/v1/imports/templates/{$kind}?format=xlsx")->assertOk();
            $this->assertStringStartsWith('PK', $xlsx->getContent(), 'xlsx is a zip container');
        }
        $this->get('/api/v1/imports/templates/nothing')->assertStatus(404);
    }

    public function test_downloaded_csv_templates_have_a_single_bom_and_can_be_uploaded_unchanged(): void
    {
        $this->actingAsRole('manager');
        foreach (['vehicle-makes', 'vehicle-models', 'fitments'] as $kind) {
            $content = $this->get("/api/v1/imports/templates/{$kind}")->assertOk()->getContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
            $this->assertStringNotContainsString("\xEF\xBB\xBF\xEF\xBB\xBF", $content, 'only one byte-order mark');
            $file = UploadedFile::fake()->createWithContent('template.csv', $content);
            $res = $this->dry($kind, $file)->assertOk();
            $this->assertSame(1, $res->json('data.summary.total_rows'), "{$kind} template parses with its headers intact");
        }
    }

    public function test_makes_and_models_import_with_row_errors_then_commit(): void
    {
        $this->actingAsRole('manager');
        $bad = $this->csv([['name', 'sort_order'], ['Rivian', '10'], ['', '5'], ['Rivian', '20'], ['Lucid', 'abc']]);
        $res = $this->dry('vehicle-makes', $bad)->assertOk()->json('data.summary');
        $this->assertSame(4, $res['total_rows']);
        $this->assertSame(3, $res['invalid_rows']);
        $codes = collect($res['errors'])->map(fn ($e) => "{$e['row']}:{$e['field']}:{$e['code']}")->all();
        $this->assertContains('3:name:REQUIRED', $codes);
        $this->assertContains('4:name:DUPLICATE_IN_FILE', $codes);
        $this->assertContains('5:sort_order:INVALID_NUMBER', $codes);
        $this->assertSame(0, VehicleMake::query()->where('name', 'Rivian')->count(), 'dry run writes nothing');

        $token = $this->dry('vehicle-makes', $this->csv([['name', 'sort_order'], ['Rivian', '10'], ['Lucid', '20'], ['Toyota', '5']]))->assertOk()->json('data');
        $this->assertSame(2, $token['summary']['would_create']);
        $this->assertSame(1, $token['summary']['would_update']);
        $done = $this->postJson('/api/v1/imports/commit', ['kind' => 'vehicle-makes', 'token' => $token['token']])->assertOk()->json('data');
        $this->assertSame(2, $done['created']);
        $this->assertSame(5, VehicleMake::query()->where('name', 'Toyota')->value('sort_order'));
        $this->postJson('/api/v1/imports/commit', ['kind' => 'vehicle-makes', 'token' => $token['token']])->assertStatus(404)->assertJsonPath('code', 'IMPORT_NOT_FOUND'); // a token works once

        $models = $this->csv([['make', 'name', 'generation_code', 'body_type', 'year_from', 'year_to'], ['Rivian', 'R1T', 'GEN1', 'Pickup', '2021', ''], ['Nobody', 'X', '', '', '', ''], ['Rivian', 'R1S', '', 'Spaceship', '2022', '2020']]);
        $r = $this->dry('vehicle-models', $models)->assertOk()->json('data.summary');
        $c = collect($r['errors'])->map(fn ($e) => "{$e['row']}:{$e['field']}:{$e['code']}")->all();
        $this->assertContains('3:make:UNKNOWN_MAKE', $c);
        $this->assertContains('4:body_type:UNKNOWN_LOOKUP', $c);
        $this->assertContains('4:year_to:INVALID_YEAR', $c);
        $ok = $this->dry('vehicle-models', $this->csv([['make', 'name', 'generation_code', 'body_type', 'year_from', 'year_to'], ['Rivian', 'R1T', 'GEN1', 'Pickup', '2021', '']]))->json('data.token');
        $this->postJson('/api/v1/imports/commit', ['kind' => 'vehicle-models', 'token' => $ok])->assertOk();
        $this->assertSame(1, VehicleModel::query()->where('name', 'R1T')->count());
    }

    public function test_products_dry_run_catches_every_kind_of_row_error_and_commit_is_blocked(): void
    {
        $this->actingAsRole('manager');
        $existing = $this->makeVariant($this->makeProduct(['code' => 'OTHER-1']), ['sku' => 'TAKEN-SKU', 'barcode' => '7770000000001']);
        $rows = $this->good();
        $rows[] = $this->prow(['product_code' => 'IM-BAD', 'name_en' => 'Bad', 'mirror_type' => 'tractor', 'category' => 'Nope', 'quality_grade' => 'Nothing', 'unit' => 'kg', 'position' => 'sideways', 'retail_price' => '-5']); // 6
        $rows[] = $this->prow(['product_code' => 'IM-AXIO', 'position' => 'pair', 'sku' => 'TAKEN-SKU', 'retail_price' => '100']); // 7: SKU of another product
        $rows[] = $this->prow(['product_code' => 'IM-AXIO', 'position' => 'universal', 'barcode' => '7770000000001', 'retail_price' => '100']); // 8: barcode of another variant
        $rows[] = $this->prow(['product_code' => 'IM-AXIO', 'position' => 'right', 'width_mm' => '180', 'height_mm' => '110', 'glass_type' => 'Convex', 'adjustment' => 'electric', 'heated' => 'yes', 'retail_price' => '1']); // 9: repeats row 2
        $rows[] = $this->prow(['product_code' => 'IM-AXIO', 'name_en' => 'Another Name', 'position' => 'interior', 'retail_price' => '1']); // 10: contradicts product name
        $rows[] = $this->prow(['position' => 'left', 'retail_price' => '1']); // 11: no product code
        $rows[] = $this->prow(['product_code' => 'IM-C2', 'name_en' => 'X', 'mirror_type' => 'side_mirror', 'category' => 'Side Mirrors', 'quality_grade' => 'Genuine OEM', 'unit' => 'pcs', 'position' => 'left', 'retail_price' => '100', 'min_price' => '500', 'heated' => 'maybe']); // 12

        $res = $this->dry('products', $this->csv($rows))->assertOk()->json('data');
        $s = $res['summary'];
        $codes = collect($s['errors'])->map(fn ($e) => "{$e['row']}:{$e['field']}:{$e['code']}")->all();
        foreach (['6:mirror_type:INVALID_ENUM', '6:category:UNKNOWN_CATEGORY', '6:quality_grade:UNKNOWN_LOOKUP', '6:unit:UNKNOWN_UNIT', '6:position:INVALID_ENUM', '7:sku:SKU_CONFLICT', '8:barcode:BARCODE_DUPLICATE', '9:position:DUPLICATE_IN_FILE', '10:name_en:PRODUCT_MISMATCH', '11:product_code:REQUIRED', '12:min_price:MIN_PRICE_ABOVE_RETAIL', '12:heated:INVALID_ENUM'] as $expect) {
            $this->assertContains($expect, $codes);
        }
        $this->assertSame(0, Product::query()->where('code', 'IM-AXIO')->count());
        $this->assertGreaterThan(0, $s['invalid_rows']);

        // commit is refused when the file has errors, and nothing is written
        $this->postJson('/api/v1/imports/commit', ['kind' => 'products', 'token' => $res['token']])->assertStatus(422)->assertJsonPath('code', 'IMPORT_HAS_ERRORS');
        $this->assertSame(0, Product::query()->where('code', 'IM-AXIO')->count());
        $this->assertSame($existing['id'], ProductVariant::query()->where('sku', 'TAKEN-SKU')->value('id'));
    }

    public function test_products_commit_creates_products_variants_oem_numbers_and_is_idempotent(): void
    {
        $this->actingAsRole('manager');
        $dry = $this->dry('products', $this->csv($this->good()))->assertOk()->json('data');
        $this->assertSame([], $dry['summary']['errors']);
        $this->assertSame(2, $dry['summary']['would_create']);
        $this->assertSame(4, $dry['summary']['total_rows']);
        $this->postJson('/api/v1/imports/commit', ['kind' => 'products', 'token' => $dry['token']])->assertOk()->assertJsonPath('data.committed', true);

        $axio = Product::query()->where('code', 'IM-AXIO')->firstOrFail();
        $this->assertTrue($axio->is_active);
        $this->assertSame(['wing mirror', 'side glass'], $axio->tags);
        $this->assertSame(6, $axio->warranty_months);
        $this->assertSame(3, $axio->variants()->count());
        $right = $axio->variants()->where('position', 'right')->firstOrFail();
        $this->assertSame('IM-AXIO-R-180X110', $right->sku);
        $this->assertSame('8801112223334', $right->barcode);
        $this->assertTrue($right->heated);
        $this->assertSame('12500.00', $right->retail_price);
        $this->assertSame(['87910-02B30', '87910-02B31'], $right->crossReferences->pluck('number')->all());
        $this->assertSame('Universal', $axio->variants()->where('retail_price', 9000)->value('size_label'));
        $this->assertSame(2, Product::query()->where('code', 'like', 'IM-%')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'import', 'entity_table' => 'imports']);

        // re-importing the same file updates instead of duplicating
        $again = $this->dry('products', $this->csv($this->good()))->assertOk()->json('data');
        $this->assertSame([], $again['summary']['errors']);
        $this->assertSame(2, $again['summary']['would_update']);
        $this->postJson('/api/v1/imports/commit', ['kind' => 'products', 'token' => $again['token']])->assertOk();
        $this->assertSame(3, $axio->variants()->count());
        $this->assertSame(2, Product::query()->where('code', 'like', 'IM-%')->count());
    }

    public function test_xlsx_files_import_like_csv(): void
    {
        $this->actingAsRole('manager');
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $w = new XlsxWriter;
        $w->openToFile($path);
        foreach ($this->good() as $r) {
            $w->addRow(Row::fromValues($r));
        }
        $w->close();
        $file = new UploadedFile($path, 'catalog.xlsx', null, null, true);
        $dry = $this->dry('products', $file)->assertOk()->json('data');
        $this->assertSame([], $dry['summary']['errors']);
        $this->assertSame(4, $dry['summary']['total_rows']);
        $this->postJson('/api/v1/imports/commit', ['kind' => 'products', 'token' => $dry['token']])->assertOk();
        $this->assertSame(3, Product::query()->where('code', 'IM-AXIO')->first()->variants()->count());
    }

    public function test_fitments_import(): void
    {
        $this->actingAsRole('manager');
        $v = $this->makeVariant($this->makeProduct());
        $head = ImportService::HEADERS['fitments'];
        $bad = $this->csv([$head, ['NOPE-SKU', 'Toyota', 'Axio', '', '2012', '2018', '', ''], [$v['sku'], 'Toyota', 'Nonexistent', '', '2012', '', '', ''], [$v['sku'], 'Toyota', 'Axio', '', '', '', '', '']]);
        $codes = collect($this->dry('fitments', $bad)->assertOk()->json('data.summary.errors'))->map(fn ($e) => "{$e['row']}:{$e['field']}:{$e['code']}")->all();
        $this->assertContains('2:sku:UNKNOWN_SKU', $codes);
        $this->assertContains('3:model:UNKNOWN_MODEL', $codes);
        $this->assertContains('4:year_from:REQUIRED', $codes);

        $good = $this->csv([$head, [$v['sku'], 'Toyota', 'Axio', '', '2012', '2018', 'Hybrid', 'BSM only'], [$v['sku'], 'Toyota', 'Allion', '', '2010', '', '', ''], [$v['sku'], 'Toyota', 'Axio', '', '2012', '2018', 'Hybrid', 'again']]);
        $s = $this->dry('fitments', $good)->assertOk()->json('data');
        $this->assertSame(['4:model:DUPLICATE_IN_FILE'], collect($s['summary']['errors'])->map(fn ($e) => "{$e['row']}:{$e['field']}:{$e['code']}")->all());
        $good2 = $this->csv([$head, [$v['sku'], 'Toyota', 'Axio', '', '2012', '2018', 'Hybrid', 'BSM only'], [$v['sku'], 'Toyota', 'Allion', '', '2010', '', '', '']]);
        $tok = $this->dry('fitments', $good2)->json('data.token');
        $this->postJson('/api/v1/imports/commit', ['kind' => 'fitments', 'token' => $tok])->assertOk();
        $this->assertSame(2, VehicleFitment::query()->where('variant_id', $v['id'])->count());
    }

    public function test_file_and_header_validation(): void
    {
        $this->actingAsRole('manager');
        $this->dry('products', UploadedFile::fake()->createWithContent('x.txt', 'a,b'))->assertStatus(422)->assertJsonPath('code', 'IMPORT_FILE_INVALID');
        $this->dry('products', $this->csv([['wrong', 'headers'], ['1', '2']]))->assertStatus(422)->assertJsonPath('code', 'IMPORT_HEADERS_INVALID')->assertJsonPath('context.missing.0', 'product_code');
        $this->dry('nothing', $this->csv([['a']]))->assertStatus(404);
        $this->postJson('/api/v1/imports/commit', ['kind' => 'products', 'token' => str_repeat('a', 40)])->assertStatus(404);
        $this->postJson('/api/v1/imports/commit', ['kind' => 'products', 'token' => 'short'])->assertStatus(422);
    }

    public function test_a_token_cannot_be_committed_by_another_user_or_as_another_kind(): void
    {
        $this->actingAsRole('manager');
        $token = $this->dry('vehicle-makes', $this->csv([['name', 'sort_order'], ['Zeekr', '1']]))->json('data.token');
        $this->postJson('/api/v1/imports/commit', ['kind' => 'vehicle-models', 'token' => $token])->assertStatus(404);
        $this->actingAsRole('super_admin');
        $this->postJson('/api/v1/imports/commit', ['kind' => 'vehicle-makes', 'token' => $token])->assertStatus(404);
        $this->actingAsRole('manager');
        $this->postJson('/api/v1/imports/commit', ['kind' => 'vehicle-makes', 'token' => $token])->assertOk();
    }

    public function test_export_round_trips_through_the_importer_and_never_contains_cost(): void
    {
        $this->actingAsRole('manager');
        $tok = $this->dry('products', $this->csv($this->good()))->json('data.token');
        $this->postJson('/api/v1/imports/commit', ['kind' => 'products', 'token' => $tok])->assertOk();
        ProductVariant::query()->update(['last_cost' => '7777.77']);

        $res = $this->get('/api/v1/products-export')->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith(implode(',', ImportService::HEADERS['products']), ltrim($csv, "\xEF\xBB\xBF"));
        $this->assertStringContainsString('IM-AXIO-R-180X110', $csv);
        $this->assertStringContainsString('87910-02B30;87910-02B31', $csv);
        $this->assertStringNotContainsString('7777', $csv);
        $this->assertStringNotContainsString('cost', strtolower(explode("\n", $csv)[0]));
        $this->assertDatabaseHas('audit_logs', ['action' => 'export', 'entity_table' => 'products']);

        $roundTrip = UploadedFile::fake()->createWithContent('export.csv', $csv);
        $summary = $this->dry('products', $roundTrip)->assertOk()->json('data.summary');
        $this->assertSame([], $summary['errors'], json_encode($summary['errors']));
        $this->assertSame(4, $summary['total_rows']);
        $this->assertSame(0, $summary['would_create']);
        $this->get('/api/v1/products-export?format=xlsx&search=civic')->assertOk();
    }

    public function test_import_permissions_are_per_kind(): void
    {
        // cashier and storekeeper hold neither products.import nor masterdata.manage
        foreach (['cashier', 'storekeeper', 'accountant'] as $role) {
            $this->actingAsRole($role);
            $this->get('/api/v1/imports/templates/products')->assertStatus(403);
            $this->post('/api/v1/imports/dry-run', ['kind' => 'products', 'file' => $this->csv([['x']])], ['Accept' => 'application/json'])->assertStatus(403);
        }
        // a role with products.import but no pricing.manage may import fitments but not products
        $this->actingAsRole('super_admin');
        $this->putJson('/api/v1/role-permissions/storekeeper', ['permissions' => ['products.import' => true]])->assertOk();
        $this->actingAsRole('storekeeper');
        $this->get('/api/v1/imports/templates/fitments')->assertOk();
        $this->get('/api/v1/imports/templates/products')->assertStatus(403);
        $this->get('/api/v1/imports/templates/vehicle-makes')->assertStatus(403);
    }
}
