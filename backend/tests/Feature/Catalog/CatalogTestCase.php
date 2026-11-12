<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\Unit;
use Tests\TestCase;

/** Shared helpers for catalog feature tests. */
abstract class CatalogTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function productPayload(array $over = []): array
    {
        return array_merge([
            'code' => 'SM-TOY-AXIO', 'name_en' => 'Side Mirror – Toyota Axio NZE141', 'mirror_type' => 'side_mirror',
            'category_id' => Category::query()->where('slug', 'side-mirrors')->value('id'),
            'quality_grade_id' => $this->lookup('quality_grade', 'aftermarket_premium'),
            'unit_id' => Unit::query()->where('code', 'pcs')->value('id'),
            'brand_id' => null, 'tags' => ['wing mirror', 'লুকিং গ্লাস'], 'warranty_months' => 6,
        ], $over);
    }

    protected function lookup(string $type, string $code): int
    {
        return (int) LookupValue::query()->where('type_id', LookupType::query()->where('key', $type)->value('id'))->where('code', $code)->value('id');
    }

    /** @return array<string, mixed> */
    protected function variantPayload(array $over = []): array
    {
        return array_merge(['position' => 'right', 'size_width_mm' => 180, 'size_height_mm' => 110, 'retail_price' => '12500.00', 'adjustment' => 'electric'], $over);
    }

    protected function makeProduct(array $over = []): int
    {
        return (int) $this->postJson('/api/v1/products', $this->productPayload($over))->assertCreated()->json('data.id');
    }

    protected function makeVariant(int $productId, array $over = []): array
    {
        return $this->postJson("/api/v1/products/{$productId}/variants", $this->variantPayload($over))->assertCreated()->json('data');
    }
}
