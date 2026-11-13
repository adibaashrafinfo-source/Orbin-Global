<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Category;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\VehicleFitment;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Services\Inventory\StockPostingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * CSV/XLSX import (FR-204) for vehicle makes, vehicle models, products with variants, and fitments.
 * Two steps: a dry run that validates every row and reports row-level errors without writing, then a commit that
 * applies the same rows in ONE transaction (all or nothing). Files are read as a stream, row by row.
 */
class ImportService
{
    public const MAX_ROWS = 5000;

    public const KINDS = ['vehicle-makes', 'vehicle-models', 'products', 'fitments', 'opening-stock'];

    /** @var array<string, array<int, string>> */
    public const HEADERS = [
        'vehicle-makes' => ['name', 'sort_order'],
        'vehicle-models' => ['make', 'name', 'generation_code', 'body_type', 'year_from', 'year_to'],
        'products' => [
            'product_code', 'name_en', 'name_bn', 'mirror_type', 'category', 'brand', 'quality_grade', 'origin_country', 'unit', 'tax_rate_code', 'warranty_months', 'tags',
            'sku', 'barcode', 'position', 'width_mm', 'height_mm', 'thickness_mm', 'size_label', 'glass_type', 'tint', 'color_finish', 'adjustment',
            'heated', 'auto_fold', 'turn_signal', 'blind_spot_sensor', 'side_camera', 'memory_tilt', 'auto_dimming', 'puddle_light', 'pin_count',
            'retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price', 'reorder_level', 'reorder_qty', 'oem_numbers',
        ],
        'fitments' => ['sku', 'make', 'model', 'generation_code', 'year_from', 'year_to', 'trim', 'note'],
        'opening-stock' => ['sku', 'warehouse', 'qty', 'unit_cost'],
    ];

    private const SAMPLES = [
        'vehicle-makes' => [['Toyota', '10']],
        'vehicle-models' => [['Toyota', 'Axio', 'NZE141', 'Sedan', '2012', '2018']],
        'products' => [['SM-TOY-AXIO', 'Side Mirror – Toyota Axio', '', 'side_mirror', 'Side Mirrors', '', 'Aftermarket premium', 'Taiwan', 'pcs', 'VAT0', '6', 'wing mirror;side glass', '', '', 'right', '180', '110', '', '', 'Convex', '', 'Black', 'electric', 'yes', '', '', '', '', '', '', '', '', '12500', '11000', '10500', '', '9500', '5', '10', '87910-02B30;87910-02B31']],
        'fitments' => [['SM-TOY-AXIO-R-180X110', 'Toyota', 'Axio', 'NZE141', '2012', '2018', 'Hybrid', 'with BSM only']],
        'opening-stock' => [['SM-TOY-AXIO-R-180X110', 'SHOW', '12', '9800']],
    ];

    private const FLAGS = ['heated', 'auto_fold', 'turn_signal', 'blind_spot_sensor', 'side_camera', 'memory_tilt', 'auto_dimming', 'puddle_light'];

    /** @var array<string, array<string, int>> lookup caches */
    private array $cache = [];

    public function __construct(
        private readonly ProductService $products,
        private readonly VariantService $variants,
        private readonly StockPostingService $stock,
    ) {}

    // ---------------------------------------------------------------- templates

    /** @return array{0: string, 1: string} [content, filename] */
    public function template(string $kind, string $format): array
    {
        $this->assertKind($kind);
        $rows = array_merge([self::HEADERS[$kind]], self::SAMPLES[$kind]);
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues($r));
        }
        $writer->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return [$content, "orbin-{$kind}-template.".($format === 'xlsx' ? 'xlsx' : 'csv')];
    }

    // ---------------------------------------------------------------- staging

    /** @return array{token: string, summary: array<string, mixed>} */
    public function dryRun(string $kind, UploadedFile $file, int $userId): array
    {
        $this->assertKind($kind);
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'xlsx'], true) || $file->getSize() > 10 * 1024 * 1024) {
            throw new ApiException('IMPORT_FILE_INVALID', 422, '', ['file' => ['errors.IMPORT_FILE_INVALID']]);
        }
        $token = Str::lower(Str::random(40));
        $dir = storage_path('app/private/imports');
        File::ensureDirectoryExists($dir);
        $file->move($dir, "{$token}.{$ext}");
        file_put_contents("{$dir}/{$token}.json", json_encode(['kind' => $kind, 'ext' => $ext, 'user_id' => $userId, 'name' => $file->getClientOriginalName(), 'at' => now()->toIso8601String()]));
        $summary = $this->analyse($kind, "{$dir}/{$token}.{$ext}", $ext, false);

        return ['token' => $token, 'summary' => $summary + ['file_name' => $file->getClientOriginalName()]];
    }

    /** @return array<string, mixed> */
    public function commit(string $token, int $userId, string $expectedKind): array
    {
        $dir = storage_path('app/private/imports');
        if (! preg_match('/^[a-z0-9]{40}$/', $token) || ! is_file("{$dir}/{$token}.json")) {
            throw new ApiException('IMPORT_NOT_FOUND', 404);
        }
        $meta = json_decode((string) file_get_contents("{$dir}/{$token}.json"), true);
        if (($meta['user_id'] ?? 0) !== $userId || $meta['kind'] !== $expectedKind) {
            throw new ApiException('IMPORT_NOT_FOUND', 404);
        }
        $path = "{$dir}/{$token}.{$meta['ext']}";
        $result = $this->analyse($meta['kind'], $path, $meta['ext'], true);
        @unlink($path);
        @unlink("{$dir}/{$token}.json");

        return $result;
    }

    // ---------------------------------------------------------------- core

    /** @return array<string, mixed> */
    private function analyse(string $kind, string $path, string $ext, bool $commit): array
    {
        $headers = null;
        $records = [];
        $errors = [];
        $total = 0;
        foreach ($this->read($path, $ext) as $rowNo => $cells) {
            if ($headers === null) {
                $headers = $this->normaliseHeaders($cells);
                $missing = array_values(array_diff($this->requiredHeaders($kind), $headers));
                if ($missing !== []) {
                    throw new ApiException('IMPORT_HEADERS_INVALID', 422, '', [], ['missing' => $missing, 'expected' => self::HEADERS[$kind]]);
                }

                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $h) {
                $v = $cells[$i] ?? null;
                $assoc[$h] = is_string($v) ? trim($v) : ($v === null ? '' : trim((string) $v));
            }
            if (implode('', $assoc) === '') {
                continue;
            }
            if (++$total > self::MAX_ROWS) {
                throw new ApiException('IMPORT_TOO_LARGE', 422, '', [], ['max_rows' => self::MAX_ROWS]);
            }
            $records[] = ['row' => $rowNo, 'raw' => $assoc];
        }
        if ($headers === null) {
            throw new ApiException('IMPORT_HEADERS_INVALID', 422, '', [], ['missing' => $this->requiredHeaders($kind), 'expected' => self::HEADERS[$kind]]);
        }
        $this->cache = [];
        $plan = match ($kind) {
            'vehicle-makes' => $this->planMakes($records, $errors),
            'vehicle-models' => $this->planModels($records, $errors),
            'products' => $this->planProducts($records, $errors),
            'opening-stock' => $this->planOpeningStock($records, $errors),
            default => $this->planFitments($records, $errors),
        };
        $summary = [
            'kind' => $kind, 'total_rows' => $total, 'valid_rows' => $total - count(array_unique(array_column($errors, 'row'))),
            'invalid_rows' => count(array_unique(array_column($errors, 'row'))), 'errors' => array_slice($errors, 0, 200), 'errors_truncated' => count($errors) > 200,
            'error_count' => count($errors), 'would_create' => $plan['create'], 'would_update' => $plan['update'], 'preview' => array_slice(array_column($records, 'raw'), 0, 15),
        ];
        if (! $commit) {
            return $summary;
        }
        if ($errors !== []) {
            throw new ApiException('IMPORT_HAS_ERRORS', 422, '', [], ['error_count' => count($errors), 'errors' => array_slice($errors, 0, 50)]);
        }
        DB::transaction(function () use ($plan): void {
            foreach ($plan['apply'] as $apply) {
                try {
                    $apply['run']();
                } catch (ApiException $e) {
                    throw new ApiException('IMPORT_ROW_FAILED', 422, '', [], ['row' => $apply['row'], 'code' => $e->errorCode, 'context' => $e->context]);
                }
            }
        });

        return $summary + ['committed' => true, 'created' => $plan['create'], 'updated' => $plan['update']];
    }

    // ---------------------------------------------------------------- planners

    /** @param array<int, array{row: int, raw: array<string, string>}> $records */
    private function planMakes(array $records, array &$errors): array
    {
        $apply = [];
        $create = 0;
        $update = 0;
        $seen = [];
        foreach ($records as $rec) {
            $r = $rec['raw'];
            $name = $r['name'] ?? '';
            if ($name === '') {
                $errors[] = $this->err($rec['row'], 'name', 'REQUIRED');

                continue;
            }
            if (isset($seen[mb_strtolower($name)])) {
                $errors[] = $this->err($rec['row'], 'name', 'DUPLICATE_IN_FILE', $name);

                continue;
            }
            $seen[mb_strtolower($name)] = true;
            $sort = $this->int($r['sort_order'] ?? '', $rec['row'], 'sort_order', $errors, 0, 100000);
            $existing = VehicleMake::query()->where('name', $name)->first();
            $existing ? $update++ : $create++;
            $apply[] = ['row' => $rec['row'], 'run' => function () use ($existing, $name, $sort): void {
                $existing ? $existing->update(array_filter(['sort_order' => $sort], fn ($v) => $v !== null)) : VehicleMake::query()->create(['name' => $name, 'sort_order' => $sort ?? 0]);
            }];
        }

        return compact('apply', 'create', 'update');
    }

    private function planModels(array $records, array &$errors): array
    {
        $apply = [];
        $create = 0;
        $update = 0;
        $seen = [];
        foreach ($records as $rec) {
            $r = $rec['raw'];
            $row = $rec['row'];
            $makeName = $r['make'] ?? '';
            $name = $r['name'] ?? '';
            $gen = $r['generation_code'] ?? '';
            if ($makeName === '') {
                $errors[] = $this->err($row, 'make', 'REQUIRED');
            }
            if ($name === '') {
                $errors[] = $this->err($row, 'name', 'REQUIRED');
            }
            $make = $makeName !== '' ? VehicleMake::query()->where('name', $makeName)->first() : null;
            if ($makeName !== '' && ! $make && ! $this->willCreateMake($records, $makeName)) {
                $errors[] = $this->err($row, 'make', 'UNKNOWN_MAKE', $makeName);
            }
            $body = ($r['body_type'] ?? '') !== '' ? $this->lookupId('body_type', $r['body_type']) : null;
            if (($r['body_type'] ?? '') !== '' && ! $body) {
                $errors[] = $this->err($row, 'body_type', 'UNKNOWN_LOOKUP', $r['body_type']);
            }
            $from = $this->int($r['year_from'] ?? '', $row, 'year_from', $errors, 1950, 2100);
            $to = $this->int($r['year_to'] ?? '', $row, 'year_to', $errors, 1950, 2100);
            if ($from !== null && $to !== null && $to < $from) {
                $errors[] = $this->err($row, 'year_to', 'INVALID_YEAR', (string) $to);
            }
            $key = mb_strtolower("{$makeName}|{$name}|{$gen}");
            if ($name !== '' && isset($seen[$key])) {
                $errors[] = $this->err($row, 'name', 'DUPLICATE_IN_FILE', $name);
            }
            $seen[$key] = true;
            if ($make) {
                $existing = VehicleModel::query()->where(['make_id' => $make->id, 'name' => $name, 'generation_code' => $gen])->first();
                $existing ? $update++ : $create++;
            } else {
                $existing = null;
                $create++;
            }
            $apply[] = ['row' => $row, 'run' => function () use ($makeName, $name, $gen, $body, $from, $to): void {
                $make = VehicleMake::query()->where('name', $makeName)->firstOrFail();
                $data = array_filter(['body_type_id' => $body, 'year_from' => $from, 'year_to' => $to], fn ($v) => $v !== null);
                $existing = VehicleModel::query()->where(['make_id' => $make->id, 'name' => $name, 'generation_code' => $gen])->first();
                $existing ? $existing->update($data) : VehicleModel::query()->create($data + ['make_id' => $make->id, 'name' => $name, 'generation_code' => $gen]);
            }];
        }

        return compact('apply', 'create', 'update');
    }

    /** @param array<int, array{row: int, raw: array<string, string>}> $records */
    private function willCreateMake(array $records, string $make): bool
    {
        return false; // models are imported after makes in a separate file; unknown makes are an error
    }

    private function planProducts(array $records, array &$errors): array
    {
        $apply = [];
        $create = 0;
        $update = 0;
        $firstRowOfProduct = [];
        $productsSeen = [];
        $skusInFile = [];
        $sigsInFile = [];
        $barcodesInFile = [];
        foreach ($records as $rec) {
            $r = $rec['raw'];
            $row = $rec['row'];
            $code = strtoupper($r['product_code'] ?? '');
            if ($code === '') {
                $errors[] = $this->err($row, 'product_code', 'REQUIRED');

                continue;
            }
            if (! preg_match('/^[A-Z0-9][A-Z0-9\-]*$/', $code)) {
                $errors[] = $this->err($row, 'product_code', 'INVALID_CODE', $code);

                continue;
            }
            $productData = [];
            $isFirst = ! isset($firstRowOfProduct[$code]);
            if ($isFirst) {
                $firstRowOfProduct[$code] = $row;
                $productData = $this->productData($r, $row, $errors);
                $existingProduct = Product::query()->where('code', $code)->first();
                $existingProduct ? $update++ : $create++;
                if (! $existingProduct && ($r['name_en'] ?? '') === '') {
                    $errors[] = $this->err($row, 'name_en', 'REQUIRED');
                }
                $productsSeen[$code] = $productData;
                $apply[] = ['row' => $row, 'run' => function () use ($code, $productData): void {
                    $p = Product::query()->where('code', $code)->first();
                    $p ? $this->products->update($p, $productData) : $this->products->create($productData + ['code' => $code, 'is_active' => true]);
                }];
            } else {
                // later rows of the same product may repeat product columns but must not contradict the first row
                foreach (['name_en', 'mirror_type', 'category', 'quality_grade', 'unit'] as $f) {
                    if (($r[$f] ?? '') !== '' && isset($productsSeen[$code]['_raw'][$f]) && mb_strtolower($r[$f]) !== mb_strtolower($productsSeen[$code]['_raw'][$f])) {
                        $errors[] = $this->err($row, $f, 'PRODUCT_MISMATCH', $r[$f]);
                    }
                }
            }
            // variant
            if (($r['position'] ?? '') === '' && ($r['sku'] ?? '') === '' && ($r['retail_price'] ?? '') === '') {
                continue; // product-only row
            }
            $variantData = $this->variantData($r, $row, $errors);
            $sku = strtoupper($r['sku'] ?? '');
            if ($sku !== '') {
                if (isset($skusInFile[$sku])) {
                    $errors[] = $this->err($row, 'sku', 'DUPLICATE_IN_FILE', $sku);
                }
                $skusInFile[$sku] = true;
                $other = ProductVariant::withTrashed()->where('sku', $sku)->first();
                $prod = Product::withTrashed()->where('code', $code)->first();
                if ($other && (! $prod || $other->product_id !== $prod->id)) {
                    $errors[] = $this->err($row, 'sku', 'SKU_CONFLICT', $sku);
                }
            }
            // identity of this row: the existing variant it would update (same SKU, or same signature when no SKU is given)
            $prodId = Product::query()->where('code', $code)->value('id');
            $sig = null;
            $hit = null;
            if ($variantData !== null && isset($variantData['position'])) {
                // products that do not exist yet get a stable negative pseudo id so repeats inside the file are still caught
                $sig = VariantSupport::signature($this->signatureAttrs($prodId ?? -abs(crc32($code)), $variantData));
                $hit = $prodId ? ProductVariant::query()->where('signature', $sig)->first() : null;
            }
            $mine = $sku !== '' ? ProductVariant::withTrashed()->where('sku', $sku)->value('id') : $hit?->id;
            if (($r['barcode'] ?? '') !== '') {
                $bc = $r['barcode'];
                $owner = ProductVariant::withTrashed()->where('barcode', $bc)->first();
                if (isset($barcodesInFile[$bc]) || ($owner && $owner->id !== $mine)) {
                    $errors[] = $this->err($row, 'barcode', 'BARCODE_DUPLICATE', $bc);
                }
                $barcodesInFile[$bc] = true;
            }
            if ($sig !== null) {
                if ($hit && ! ($sku === '' || strtoupper($hit->sku) === $sku)) {
                    $errors[] = $this->err($row, 'position', 'VARIANT_DUPLICATE', $hit->sku);
                }
                if (isset($sigsInFile[$sig])) {
                    $errors[] = $this->err($row, 'position', 'DUPLICATE_IN_FILE', (string) (($r['size_label'] ?? '') !== '' ? $r['size_label'] : $r['position']));
                }
                $sigsInFile[$sig] = true;
                $oem = array_values(array_filter(array_map('trim', explode(';', (string) ($r['oem_numbers'] ?? '')))));
                $apply[] = ['row' => $row, 'run' => fn () => $this->applyVariant($code, $sku, $variantData, $oem)];
            }
        }

        return compact('apply', 'create', 'update');
    }

    private function planFitments(array $records, array &$errors): array
    {
        $apply = [];
        $create = 0;
        $seen = [];
        foreach ($records as $rec) {
            $r = $rec['raw'];
            $row = $rec['row'];
            $sku = strtoupper($r['sku'] ?? '');
            $variant = $sku !== '' ? ProductVariant::query()->where('sku', $sku)->first() : null;
            if ($sku === '') {
                $errors[] = $this->err($row, 'sku', 'REQUIRED');
            } elseif (! $variant) {
                $errors[] = $this->err($row, 'sku', 'UNKNOWN_SKU', $sku);
            }
            $make = ($r['make'] ?? '') !== '' ? VehicleMake::query()->where('name', $r['make'])->first() : null;
            $model = null;
            if (($r['make'] ?? '') === '' || ($r['model'] ?? '') === '') {
                $errors[] = $this->err($row, ($r['make'] ?? '') === '' ? 'make' : 'model', 'REQUIRED');
            } elseif (! $make) {
                $errors[] = $this->err($row, 'make', 'UNKNOWN_MAKE', $r['make']);
            } else {
                $q = VehicleModel::query()->where('make_id', $make->id)->where('name', $r['model']);
                if (($r['generation_code'] ?? '') !== '') {
                    $q->where('generation_code', $r['generation_code']);
                }
                $model = $q->first();
                if (! $model) {
                    $errors[] = $this->err($row, 'model', 'UNKNOWN_MODEL', $r['model']);
                }
            }
            $from = $this->int($r['year_from'] ?? '', $row, 'year_from', $errors, 1950, 2100);
            if (($r['year_from'] ?? '') === '') {
                $errors[] = $this->err($row, 'year_from', 'REQUIRED');
            }
            $to = $this->int($r['year_to'] ?? '', $row, 'year_to', $errors, 1950, 2100);
            if ($from !== null && $to !== null && $to < $from) {
                $errors[] = $this->err($row, 'year_to', 'INVALID_YEAR', (string) $to);
            }
            if ($variant && $model && $from !== null) {
                $sig = VehicleFitment::signatureFor($variant->id, $model->id, $from, $to, $r['trim'] ?? null);
                if (isset($seen[$sig]) || VehicleFitment::query()->where('signature', $sig)->exists()) {
                    $errors[] = $this->err($row, 'model', 'DUPLICATE_IN_FILE', (string) $r['model']);
                }
                $seen[$sig] = true;
                $create++;
                $trim = $r['trim'] ?? null;
                $note = $r['note'] ?? null;
                $apply[] = ['row' => $row, 'run' => fn () => $this->variants->addFitmentRow($variant->id, $model->id, $from, $to, $trim, $note)];
            }
        }

        return ['apply' => $apply, 'create' => $create, 'update' => 0];
    }

    /**
     * Opening stock (FR-413): one row per variant and warehouse, with its cost. A variant can only be opened once per
     * warehouse; after that stock changes through receipts, adjustments and counts.
     *
     * @param  array<int, array{row: int, raw: array<string, string>}>  $records
     */
    private function planOpeningStock(array $records, array &$errors): array
    {
        $apply = [];
        $seen = [];
        foreach ($records as $rec) {
            $r = $rec['raw'];
            $row = $rec['row'];
            $sku = strtoupper($r['sku'] ?? '');
            $variant = $sku !== '' ? ProductVariant::query()->where('sku', $sku)->first() : null;
            if ($sku === '') {
                $errors[] = $this->err($row, 'sku', 'REQUIRED');
            } elseif (! $variant) {
                $errors[] = $this->err($row, 'sku', 'UNKNOWN_SKU', $sku);
            } elseif ($variant->is_bundle) {
                $errors[] = $this->err($row, 'sku', 'BUNDLE_NO_STOCK', $sku);
            }
            $code = trim((string) ($r['warehouse'] ?? ''));
            $warehouse = $code !== '' ? Warehouse::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first() : null;
            if ($code === '') {
                $errors[] = $this->err($row, 'warehouse', 'REQUIRED');
            } elseif (! $warehouse) {
                $errors[] = $this->err($row, 'warehouse', 'UNKNOWN_WAREHOUSE', $code);
            }
            $qty = $this->num($r['qty'] ?? '', $row, 'qty', $errors, 0, 9999999);
            if (($r['qty'] ?? '') === '') {
                $errors[] = $this->err($row, 'qty', 'REQUIRED');
            } elseif ($qty !== null && $qty <= 0) {
                $errors[] = $this->err($row, 'qty', 'INVALID_NUMBER', $r['qty']);
            }
            $cost = $this->num($r['unit_cost'] ?? '', $row, 'unit_cost', $errors, 0, 99999999);
            if (($r['unit_cost'] ?? '') === '') {
                $errors[] = $this->err($row, 'unit_cost', 'REQUIRED');
            } elseif ($cost !== null && $cost <= 0) {
                $errors[] = $this->err($row, 'unit_cost', 'COST_REQUIRED', $r['unit_cost']); // BR-094: zero cost is for samples, which are adjusted in
            }
            if ($variant && $warehouse) {
                $key = $variant->id.'-'.$warehouse->id;
                if (isset($seen[$key])) {
                    $errors[] = $this->err($row, 'sku', 'DUPLICATE_IN_FILE', $sku);
                } elseif (StockMovement::query()->where(['variant_id' => $variant->id, 'warehouse_id' => $warehouse->id])->exists()) {
                    $errors[] = $this->err($row, 'sku', 'ALREADY_STOCKED', $sku);
                }
                $seen[$key] = true;
            }
            if ($variant && $warehouse && $qty !== null && $cost !== null && $qty > 0 && $cost > 0) {
                $apply[] = ['row' => $row, 'run' => fn () => $this->stock->post([
                    'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'type' => 'opening', 'qty' => number_format($qty, 3, '.', ''), 'unit_cost' => number_format($cost, 4, '.', ''),
                    'ref_table' => 'imports', 'reason' => 'Opening stock import', 'occurred_at' => now(),
                ])];
            }
        }

        return ['apply' => $apply, 'create' => count($apply), 'update' => 0];
    }

    // ---------------------------------------------------------------- row parsing

    /**
     * @param  array<string, string>  $r
     * @return array<string, mixed>
     */
    private function productData(array $r, int $row, array &$errors): array
    {
        $d = ['_raw' => $r];
        foreach (['name_en', 'name_bn', 'short_description'] as $f) {
            if (($r[$f] ?? '') !== '') {
                $d[$f] = $r[$f];
            }
        }
        if (($r['mirror_type'] ?? '') !== '') {
            if (! in_array($r['mirror_type'], Product::MIRROR_TYPES, true)) {
                $errors[] = $this->err($row, 'mirror_type', 'INVALID_ENUM', $r['mirror_type']);
            } else {
                $d['mirror_type'] = $r['mirror_type'];
            }
        } elseif (! Product::query()->where('code', strtoupper($r['product_code'] ?? ''))->exists()) {
            $errors[] = $this->err($row, 'mirror_type', 'REQUIRED');
        }
        $this->resolve($d, 'category_id', ($r['category'] ?? ''), fn ($v) => Category::query()->whereRaw('LOWER(name_en) = ?', [mb_strtolower($v)])->value('id'), 'category', 'UNKNOWN_CATEGORY', $row, $errors, ! Product::query()->where('code', strtoupper($r['product_code'] ?? ''))->exists());
        $this->resolve($d, 'brand_id', ($r['brand'] ?? ''), fn ($v) => ProductBrand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($v)])->value('id'), 'brand', 'UNKNOWN_BRAND', $row, $errors, false);
        $this->resolve($d, 'quality_grade_id', ($r['quality_grade'] ?? ''), fn ($v) => $this->lookupId('quality_grade', $v), 'quality_grade', 'UNKNOWN_LOOKUP', $row, $errors, ! Product::query()->where('code', strtoupper($r['product_code'] ?? ''))->exists());
        $this->resolve($d, 'origin_country_id', ($r['origin_country'] ?? ''), fn ($v) => $this->lookupId('origin_country', $v), 'origin_country', 'UNKNOWN_LOOKUP', $row, $errors, false);
        $this->resolve($d, 'unit_id', ($r['unit'] ?? ''), fn ($v) => Unit::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($v)])->value('id'), 'unit', 'UNKNOWN_UNIT', $row, $errors, ! Product::query()->where('code', strtoupper($r['product_code'] ?? ''))->exists());
        $this->resolve($d, 'tax_rate_id', ($r['tax_rate_code'] ?? ''), fn ($v) => TaxRate::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($v)])->value('id'), 'tax_rate_code', 'UNKNOWN_TAX', $row, $errors, false);
        $w = $this->int($r['warranty_months'] ?? '', $row, 'warranty_months', $errors, 0, 240);
        if ($w !== null) {
            $d['warranty_months'] = $w;
        }
        if (($r['tags'] ?? '') !== '') {
            $d['tags'] = array_values(array_filter(array_map('trim', explode(';', $r['tags']))));
        }

        return $d;
    }

    /**
     * @param  array<string, string>  $r
     * @return array<string, mixed>|null
     */
    private function variantData(array $r, int $row, array &$errors): ?array
    {
        $d = [];
        if (($r['position'] ?? '') === '') {
            $errors[] = $this->err($row, 'position', 'REQUIRED');

            return null;
        }
        if (! in_array($r['position'], ProductVariant::POSITIONS, true)) {
            $errors[] = $this->err($row, 'position', 'INVALID_ENUM', $r['position']);

            return null;
        }
        $d['position'] = $r['position'];
        foreach (['width_mm' => 'size_width_mm', 'height_mm' => 'size_height_mm', 'thickness_mm' => 'thickness_mm'] as $col => $attr) {
            $n = $this->num($r[$col] ?? '', $row, $col, $errors);
            if ($n !== null) {
                $d[$attr] = $n;
            }
        }
        if (($r['size_label'] ?? '') !== '') {
            $d['size_label'] = $r['size_label'];
        }
        foreach (['glass_type' => ['glass_type', 'glass_type_id'], 'tint' => ['glass_tint', 'tint_id'], 'color_finish' => ['color_finish', 'color_finish_id']] as $col => [$type, $attr]) {
            if (($r[$col] ?? '') !== '') {
                $id = $this->lookupId($type, $r[$col]);
                $id ? $d[$attr] = $id : $errors[] = $this->err($row, $col, 'UNKNOWN_LOOKUP', $r[$col]);
            }
        }
        if (($r['adjustment'] ?? '') !== '') {
            in_array($r['adjustment'], ProductVariant::ADJUSTMENTS, true) ? $d['adjustment'] = $r['adjustment'] : $errors[] = $this->err($row, 'adjustment', 'INVALID_ENUM', $r['adjustment']);
        }
        foreach (self::FLAGS as $f) {
            $b = $this->bool($r[$f] ?? '');
            if ($b === null && ($r[$f] ?? '') !== '') {
                $errors[] = $this->err($row, $f, 'INVALID_ENUM', $r[$f]);
            } elseif ($b !== null) {
                $d[$f] = $b;
            }
        }
        $pin = $this->int($r['pin_count'] ?? '', $row, 'pin_count', $errors, 0, 99);
        if ($pin !== null) {
            $d['pin_count'] = $pin;
        }
        foreach (['retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price'] as $p) {
            $n = $this->num($r[$p] ?? '', $row, $p, $errors, 0, 99999999.99);
            if ($n !== null) {
                $d[$p] = number_format($n, 2, '.', '');
            }
        }
        if (! isset($d['retail_price']) && ! ProductVariant::query()->where('sku', strtoupper($r['sku'] ?? ''))->exists()) {
            $errors[] = $this->err($row, 'retail_price', 'REQUIRED');
        }
        if (isset($d['min_price'], $d['retail_price']) && (float) $d['min_price'] > (float) $d['retail_price']) {
            $errors[] = $this->err($row, 'min_price', 'MIN_PRICE_ABOVE_RETAIL', $d['min_price']);
        }
        foreach (['reorder_level', 'reorder_qty'] as $q) {
            $n = $this->num($r[$q] ?? '', $row, $q, $errors, 0, 99999999);
            if ($n !== null) {
                $d[$q] = $n;
            }
        }
        if (($r['barcode'] ?? '') !== '') {
            $d['barcode'] = $r['barcode'];
        }

        return $d;
    }

    /**
     * @param  array<string, mixed>  $variantData
     * @param  array<int, string>  $oem
     */
    private function applyVariant(string $code, string $sku, array $variantData, array $oem): void
    {
        $product = Product::query()->where('code', $code)->firstOrFail();
        $existing = $sku !== '' ? ProductVariant::withTrashed()->where('sku', $sku)->first() : null;
        if (! $existing) {
            $sig = VariantSupport::signature($this->signatureAttrs($product->id, $variantData));
            $existing = ProductVariant::query()->where('signature', $sig)->first();
        }
        $variant = $existing ? $this->variants->update($existing, $variantData) : $this->variants->create($product, $variantData + ($sku !== '' ? ['sku' => $sku] : []));
        foreach ($oem as $number) {
            try {
                $this->variants->addCrossReference($variant, ['ref_type' => 'oem', 'number' => $number]);
            } catch (ApiException $e) {
                if ($e->errorCode !== 'CONFLICT') {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $variantData
     * @return array<string, mixed>
     */
    private function signatureAttrs(int $productId, array $variantData): array
    {
        $label = VariantSupport::sizeLabel($variantData['size_width_mm'] ?? null, $variantData['size_height_mm'] ?? null, (string) ($variantData['size_label'] ?? ''));

        return array_merge($variantData, ['product_id' => $productId, 'size_label' => $label]);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $d */
    private function resolve(array &$d, string $attr, string $value, callable $finder, string $field, string $code, int $row, array &$errors, bool $required): void
    {
        if ($value === '') {
            if ($required) {
                $errors[] = $this->err($row, $field, 'REQUIRED');
            }

            return;
        }
        $id = $finder($value);
        $id ? $d[$attr] = (int) $id : $errors[] = $this->err($row, $field, $code, $value);
    }

    private function lookupId(string $typeKey, string $value): ?int
    {
        $this->cache[$typeKey] ??= (function () use ($typeKey): array {
            $type = LookupType::query()->where('key', $typeKey)->first();
            $map = [];
            if ($type) {
                foreach (LookupValue::query()->where('type_id', $type->id)->get() as $v) {
                    $map[mb_strtolower($v->label_en)] = $v->id;
                    $map[mb_strtolower($v->code)] = $v->id;
                }
            }

            return $map;
        })();

        return $this->cache[$typeKey][mb_strtolower(trim($value))] ?? null;
    }

    private function bool(string $v): ?bool
    {
        $v = mb_strtolower($v);
        if ($v === '') {
            return null;
        }

        return in_array($v, ['1', 'yes', 'y', 'true', 'x'], true) ? true : (in_array($v, ['0', 'no', 'n', 'false'], true) ? false : null);
    }

    private function int(string $v, int $row, string $field, array &$errors, int $min, int $max): ?int
    {
        if ($v === '') {
            return null;
        }
        if (! preg_match('/^-?\d+(\.0+)?$/', $v) || (int) $v < $min || (int) $v > $max) {
            $errors[] = $this->err($row, $field, 'INVALID_NUMBER', $v);

            return null;
        }

        return (int) $v;
    }

    private function num(string $v, int $row, string $field, array &$errors, float $min = 0, float $max = 99999999): ?float
    {
        if ($v === '') {
            return null;
        }
        $clean = str_replace(',', '', $v);
        if (! is_numeric($clean) || (float) $clean < $min || (float) $clean > $max) {
            $errors[] = $this->err($row, $field, (float) $clean < 0 && is_numeric($clean) ? 'NEGATIVE_NUMBER' : 'INVALID_NUMBER', $v);

            return null;
        }

        return (float) $clean;
    }

    /** @return array{row: int, field: string, code: string, value: string|null} */
    private function err(int $row, string $field, string $code, ?string $value = null): array
    {
        return ['row' => $row, 'field' => $field, 'code' => $code, 'value' => $value];
    }

    /** @return array<int, string> */
    private function requiredHeaders(string $kind): array
    {
        return match ($kind) {
            'vehicle-makes' => ['name'],
            'vehicle-models' => ['make', 'name'],
            'products' => ['product_code', 'position', 'retail_price'],
            'opening-stock' => ['sku', 'warehouse', 'qty', 'unit_cost'],
            default => ['sku', 'make', 'model', 'year_from'],
        };
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<int, string>
     */
    private function normaliseHeaders(array $cells): array
    {
        return array_map(fn ($c) => Str::snake(mb_strtolower(trim(preg_replace('/^(\xEF\xBB\xBF)+/', '', (string) $c) ?? ''))), $cells);
    }

    /** @return \Generator<int, array<int, mixed>> row number => cells (1-based, header is row 1) */
    private function read(string $path, string $ext): \Generator
    {
        $reader = $ext === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    yield $n => array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : $c, $row->toArray());
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw ApiException::notFound();
        }
    }
}
