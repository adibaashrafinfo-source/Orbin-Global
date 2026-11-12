<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CustomFieldService;
use Illuminate\Support\Facades\DB;

/** Product (mirror family) lifecycle: create, update with derived search text, publish, clone, delete. */
class ProductService
{
    private const WRITABLE = [
        'code', 'name_en', 'name_bn', 'mirror_type', 'category_id', 'brand_id', 'quality_grade_id', 'origin_country_id', 'short_description',
        'description', 'warranty_months', 'return_window_days', 'tax_rate_id', 'unit_id', 'tags', 'custom', 'is_active',
    ];

    public function __construct(private readonly CustomFieldService $custom, private readonly VariantService $variants) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $attrs = $this->prepare($data, null);
            $attrs['is_active'] = (bool) ($attrs['is_active'] ?? false); // new products stay drafts until published
            $this->assertCodeFree($attrs['code'], null);
            $product = Product::query()->create($attrs);
            $this->refreshSearch($product);

            return $product->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $attrs = $this->prepare($data, $product);
            if (isset($attrs['code'])) {
                $this->assertCodeFree($attrs['code'], $product->id);
            }
            if (array_key_exists('is_active', $attrs) && $attrs['is_active'] && ! $product->is_active) {
                $this->assertPublishable($product);
            }
            $product->fill($attrs)->save();
            $this->refreshSearch($product);
            $this->rebuildVariantSearch($product);

            return $product->refresh();
        });
    }

    /** Publishing needs at least one active variant (the editor's last step). */
    public function publish(Product $product): Product
    {
        $this->assertPublishable($product);
        $product->update(['is_active' => true]);

        return $product->refresh();
    }

    public function deactivate(Product $product): Product
    {
        // FR-308: deactivating a product that still has stock needs confirmation; stock levels arrive with inventory in Phase 2.
        $product->update(['is_active' => false]);

        return $product->refresh();
    }

    /** Copies the product and its variants (new SKUs, no barcodes) and their fitments. Images and OEM numbers are not copied. */
    public function cloneProduct(Product $source, ?string $newCode = null): Product
    {
        return DB::transaction(function () use ($source, $newCode): Product {
            $code = strtoupper(trim((string) $newCode)) ?: $this->freeCode($source->code.'-COPY');
            $this->assertCodeFree($code, null);
            $copy = Product::query()->create(array_merge(
                $source->only(self::WRITABLE),
                ['code' => $code, 'name_en' => $source->name_en.' (copy)', 'is_active' => false, 'primary_image_url' => null],
            ));
            foreach ($source->variants as $variant) {
                /** @var ProductVariant $variant */
                $data = array_intersect_key($variant->getAttributes(), array_flip(['position', 'size_width_mm', 'size_height_mm', 'thickness_mm', 'size_label', 'weight_g', 'glass_type_id', 'tint_id', 'is_glass_only', 'color_finish_id', 'cover_included', 'adjustment', 'pin_count', 'connector_note', 'supplier_part_no', 'year_from', 'year_to', 'retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price', 'tax_rate_id', 'reorder_level', 'reorder_qty', 'max_stock', ...ProductVariant::FEATURE_FLAGS]));
                $data['custom'] = $variant->custom;
                $data['is_active'] = true;
                $new = $this->variants->create($copy, $data);
                foreach ($variant->fitments as $f) {
                    $this->variants->addFitmentRow($new->id, $f->vehicle_model_id, $f->year_from, $f->year_to, $f->trim, $f->note);
                }
            }
            $this->refreshSearch($copy);

            return $copy->refresh();
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            foreach ($product->variants as $variant) {
                $this->variants->delete($variant);
            }
            $product->delete();
        });
    }

    public function refreshSearch(Product $product): void
    {
        $product->unsetRelations();
        $product->forceFill(['search_text' => SearchText::product($product)])->saveQuietly();
    }

    private function rebuildVariantSearch(Product $product): void
    {
        $product->variants()->get()->each(fn (ProductVariant $v) => $this->variants->refreshDerived($v));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data, ?Product $existing): array
    {
        $attrs = array_intersect_key($data, array_flip(self::WRITABLE));
        if (isset($attrs['code'])) {
            $attrs['code'] = strtoupper(trim((string) $attrs['code']));
        }
        if (array_key_exists('tags', $attrs)) {
            $attrs['tags'] = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), (array) $attrs['tags']), fn ($t) => $t !== '')));
        }
        if (array_key_exists('custom', $attrs) || $existing === null) {
            $attrs['custom'] = $this->custom->normalize('product', $attrs['custom'] ?? null);
        }
        if ($existing === null) {
            $attrs['warranty_months'] = (int) ($attrs['warranty_months'] ?? 0);
        }

        return $attrs;
    }

    private function assertCodeFree(string $code, ?int $ignoreId): void
    {
        $hit = Product::withTrashed()->where('code', $code)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->first();
        if ($hit) {
            throw new ApiException('CODE_DUPLICATE', 409, '', ['code' => ['errors.CODE_DUPLICATE']], ['existing_product_id' => $hit->id]);
        }
    }

    private function freeCode(string $base): string
    {
        $code = $base;
        $n = 2;
        while (Product::withTrashed()->where('code', $code)->exists()) {
            $code = $base.'-'.$n++;
        }

        return $code;
    }

    /** A product can go live with at least one active variant and every required custom field filled (FR-203, FR-302 review step). */
    private function assertPublishable(Product $product): void
    {
        $variants = $product->variants()->where('is_active', true)->get();
        if ($variants->isEmpty()) {
            throw new ApiException('PRODUCT_INCOMPLETE', 422);
        }
        try {
            $this->custom->normalize('product', $product->custom, true);
            foreach ($variants as $variant) {
                $this->custom->normalize('variant', $variant->custom, true);
            }
        } catch (ApiException $e) {
            throw new ApiException('PRODUCT_INCOMPLETE', 422, '', $e->fields);
        }
    }
}
