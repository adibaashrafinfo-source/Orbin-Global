<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Variant matrix generator (FR-303): positions × sizes × finishes × feature sets become variants with automatic SKUs.
 * Combinations that already exist (or repeat inside the request) are skipped and reported; nothing is overwritten.
 */
class MatrixService
{
    public const MAX_COMBINATIONS = 200;

    public function __construct(private readonly VariantService $variants) {}

    /**
     * @param  array<string, mixed>  $spec  positions[], sizes[{width_mm,height_mm,thickness_mm?,label?}], color_finish_ids[], feature_sets[], defaults{}
     * @return array{plan: array<int, array<string, mixed>>, created: array<int, int>, skipped: int}
     */
    public function generate(Product $product, array $spec, bool $dryRun): array
    {
        $plan = $this->plan($product, $spec);
        if ($dryRun) {
            return ['plan' => $plan, 'created' => [], 'skipped' => count(array_filter($plan, fn ($r) => $r['duplicate']))];
        }
        $created = DB::transaction(function () use ($product, $plan): array {
            $ids = [];
            foreach ($plan as $row) {
                if ($row['duplicate']) {
                    continue;
                }
                $ids[] = $this->variants->create($product, $row['data'])->id;
            }

            return $ids;
        });

        return ['plan' => array_map(fn ($r) => array_diff_key($r, ['data' => 1]), $plan), 'created' => $created, 'skipped' => count($plan) - count($created)];
    }

    /** @return array<int, array<string, mixed>> */
    private function plan(Product $product, array $spec): array
    {
        $positions = array_values(array_unique($spec['positions'] ?? []));
        $sizes = $spec['sizes'] ?? [[]];
        $finishes = $spec['color_finish_ids'] ?? [];
        $finishes = $finishes === [] ? [null] : array_values($finishes);
        $featureSets = $spec['feature_sets'] ?? [[]];
        $featureSets = $featureSets === [] ? [[]] : $featureSets;
        $defaults = $spec['defaults'] ?? [];

        $total = count($positions) * count($sizes) * count($finishes) * count($featureSets);
        if ($total === 0) {
            throw new ApiException('VALIDATION', 422, '', ['positions' => ['errors.REQUIRED']]);
        }
        if ($total > self::MAX_COMBINATIONS) {
            throw new ApiException('MATRIX_TOO_LARGE', 422, '', [], ['combinations' => $total, 'max' => self::MAX_COMBINATIONS]);
        }

        $plan = [];
        $seen = [];
        foreach ($positions as $position) {
            foreach ($sizes as $size) {
                foreach ($finishes as $finish) {
                    foreach ($featureSets as $set) {
                        $data = array_merge($defaults, $set, [
                            'position' => $position,
                            'size_width_mm' => $size['width_mm'] ?? null, 'size_height_mm' => $size['height_mm'] ?? null,
                            'thickness_mm' => $size['thickness_mm'] ?? ($defaults['thickness_mm'] ?? null), 'size_label' => $size['label'] ?? '',
                            'color_finish_id' => $finish ?? ($defaults['color_finish_id'] ?? null),
                        ]);
                        unset($data['sku'], $data['barcode']);
                        $sizeLabel = VariantSupport::sizeLabel($data['size_width_mm'], $data['size_height_mm'], (string) $data['size_label']);
                        $sigInput = array_merge($data, ['product_id' => $product->id, 'size_label' => $sizeLabel]);
                        $signature = VariantSupport::signature($sigInput);
                        $existing = ProductVariant::query()->where('signature', $signature)->first();
                        $duplicate = $existing !== null || isset($seen[$signature]);
                        $seen[$signature] = true;
                        $plan[] = [
                            'position' => $position, 'size_label' => $sizeLabel,
                            'sku' => VariantSupport::skuBase($product->code, $position, $sizeLabel, isset($data['size_width_mm']) ? (float) $data['size_width_mm'] : null, isset($data['size_height_mm']) ? (float) $data['size_height_mm'] : null),
                            'variant_name' => VariantSupport::variantName($sigInput, null, null, null),
                            'duplicate' => $duplicate, 'existing_variant_id' => $existing?->id, 'data' => $data,
                        ];
                    }
                }
            }
        }

        return $plan;
    }
}
