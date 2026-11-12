<?php

namespace Tests\Feature\Catalog;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\VariantService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-023: parallel requests for a duplicate variant, SKU, barcode or fitment → exactly one wins and the unique keys block the rest.
 * Real forked processes with their own database connections; fixtures are committed (and removed afterwards) so the children can see them.
 */
class CatalogConcurrencyTest extends TestCase
{
    private const CODE = 'ZZ-CONC';

    /** No wrapping transaction: the racing processes must see committed fixtures and no test-held row locks. */
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl is required to prove the unique-key guards under concurrency.');
        }
        config(['database.connections.mysql_raw' => config('database.connections.mysql')]);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function raw()
    {
        return DB::connection('mysql_raw');
    }

    private function cleanup(): void
    {
        $raw = $this->raw();
        $productIds = $raw->table('products')->where('code', self::CODE)->pluck('id')->all();
        if ($productIds) {
            $variantIds = $raw->table('product_variants')->whereIn('product_id', $productIds)->pluck('id')->all();
            $raw->table('vehicle_fitments')->whereIn('variant_id', $variantIds)->delete();
            $raw->table('oem_cross_references')->whereIn('variant_id', $variantIds)->delete();
            $raw->table('product_variants')->whereIn('product_id', $productIds)->delete();
            $raw->table('products')->whereIn('id', $productIds)->delete();
        }
        $raw->table('audit_logs')->whereIn('entity_table', ['product_variants', 'vehicle_fitments', 'oem_cross_references'])->delete();
    }

    private function committedProduct(): int
    {
        return (int) $this->raw()->table('products')->insertGetId([
            'code' => self::CODE, 'name_en' => 'Concurrency fixture', 'mirror_type' => 'side_mirror',
            'category_id' => $this->raw()->table('categories')->value('id'),
            'quality_grade_id' => $this->raw()->table('lookup_values')->value('id'),
            'unit_id' => $this->raw()->table('units')->value('id'), 'is_active' => 1, 'warranty_months' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Runs $work(i) in $n forked processes at (almost) the same moment and returns each child's result string.
     *
     * @param  callable(int): string  $work
     * @return array<int, string>
     */
    private function race(int $n, callable $work): array
    {
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $barrier = $dir.'/go-'.uniqid();
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                // Keep the inherited PDO alive so its socket is not closed under the parent, then use a fresh connection.
                $inherited = DB::connection()->getPdo();
                DB::reconnect('mysql');
                $deadline = microtime(true) + 5;
                while (! file_exists($barrier) && microtime(true) < $deadline) {
                    usleep(500);
                }
                try {
                    $result = $work($i);
                } catch (ApiException $e) {
                    $result = $e->errorCode;
                } catch (\Throwable $e) {
                    $result = 'ERROR:'.get_class($e).':'.$e->getMessage();
                }
                file_put_contents($dir."/res-{$i}-".getmypid().'.txt', $result);
                unset($inherited);
                posix_kill(getmypid(), SIGKILL);
                exit(0);
            }
            $pids[] = $pid;
        }
        usleep(150_000);
        touch($barrier);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        @unlink($barrier);
        $results = [];
        foreach (glob($dir.'/res-*.txt') ?: [] as $file) {
            $results[] = (string) file_get_contents($file);
            unlink($file);
        }
        sort($results);

        return $results;
    }

    public function test_identical_variants_with_different_skus_create_exactly_one(): void
    {
        $pid = $this->committedProduct();
        $results = $this->race(6, function (int $i) use ($pid): string {
            $v = app(VariantService::class)->create(Product::query()->findOrFail($pid), [
                'sku' => "ZZ-CONC-SKU-{$i}", 'position' => 'right', 'size_width_mm' => 180, 'size_height_mm' => 110, 'adjustment' => 'electric', 'retail_price' => '100.00',
            ]);

            return 'OK:'.$v->id;
        });
        $ok = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'OK:')));
        $this->assertCount(1, $ok, 'exactly one request may win: '.json_encode($results));
        $this->assertSame(array_fill(0, 5, 'VARIANT_DUPLICATE'), array_values(array_filter($results, fn ($r) => ! str_starts_with($r, 'OK:'))), json_encode($results));
        $this->assertSame(1, $this->raw()->table('product_variants')->where('product_id', $pid)->count());
    }

    public function test_same_sku_on_different_variants_creates_exactly_one(): void
    {
        $pid = $this->committedProduct();
        $positions = ['left', 'right', 'interior', 'pair', 'universal'];
        $results = $this->race(5, function (int $i) use ($pid, $positions): string {
            $v = app(VariantService::class)->create(Product::query()->findOrFail($pid), ['sku' => 'ZZ-CONC-SAME', 'position' => $positions[$i], 'retail_price' => '100.00']);

            return 'OK:'.$v->id;
        });
        $this->assertCount(1, array_filter($results, fn ($r) => str_starts_with($r, 'OK:')), json_encode($results));
        $this->assertSame(array_fill(0, 4, 'SKU_DUPLICATE'), array_values(array_filter($results, fn ($r) => ! str_starts_with($r, 'OK:'))), json_encode($results));
        $this->assertSame(1, $this->raw()->table('product_variants')->where('product_id', $pid)->count());
    }

    public function test_same_barcode_on_different_variants_creates_exactly_one(): void
    {
        $pid = $this->committedProduct();
        $positions = ['left', 'right', 'interior', 'pair', 'universal'];
        $results = $this->race(5, function (int $i) use ($pid, $positions): string {
            $v = app(VariantService::class)->create(Product::query()->findOrFail($pid), ['position' => $positions[$i], 'barcode' => '9990000000017', 'retail_price' => '100.00']);

            return 'OK:'.$v->id;
        });
        $this->assertCount(1, array_filter($results, fn ($r) => str_starts_with($r, 'OK:')), json_encode($results));
        $this->assertSame(array_fill(0, 4, 'BARCODE_DUPLICATE'), array_values(array_filter($results, fn ($r) => ! str_starts_with($r, 'OK:'))), json_encode($results));
        $this->assertSame(1, $this->raw()->table('product_variants')->where('barcode', '9990000000017')->count());
    }

    public function test_the_same_fitment_row_is_added_exactly_once(): void
    {
        $pid = $this->committedProduct();
        $variant = app(VariantService::class)->create(Product::query()->findOrFail($pid), ['position' => 'right', 'retail_price' => '100.00']);
        $modelId = (int) $this->raw()->table('vehicle_models')->where('name', 'Axio')->value('id');
        $results = $this->race(6, function () use ($variant, $modelId): string {
            app(VariantService::class)->addFitmentRow($variant->id, $modelId, 2012, 2018, 'Hybrid', null);

            return 'OK';
        });
        $this->assertSame(['FITMENT_DUPLICATE', 'FITMENT_DUPLICATE', 'FITMENT_DUPLICATE', 'FITMENT_DUPLICATE', 'FITMENT_DUPLICATE', 'OK'], $results, json_encode($results));
        $this->assertSame(1, $this->raw()->table('vehicle_fitments')->where('variant_id', $variant->id)->count());
    }

    public function test_generated_barcodes_never_collide_when_generated_in_parallel(): void
    {
        $pid = $this->committedProduct();
        $positions = ['left', 'right', 'interior', 'pair', 'universal'];
        $ids = [];
        foreach ($positions as $pos) {
            $ids[] = app(VariantService::class)->create(Product::query()->findOrFail($pid), ['position' => $pos, 'retail_price' => '100.00'])->id;
        }
        $results = $this->race(5, fn (int $i) => 'BC:'.app(VariantService::class)->generateBarcode(ProductVariant::query()->findOrFail($ids[$i]))->barcode);
        $codes = array_map(fn ($r) => substr($r, 3), $results);
        $this->assertCount(5, array_unique($codes), json_encode($results));
        foreach ($results as $r) {
            $this->assertStringStartsWith('BC:200', $r);
        }
    }
}
