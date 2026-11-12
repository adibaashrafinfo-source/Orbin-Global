<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\LookupValue;
use App\Models\OemCrossReference;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantBundleItem;
use App\Models\VehicleFitment;
use App\Services\AuditService;
use App\Services\CustomFieldService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Creates and changes variants. Uniqueness (BR-092 signature, BR-093 SKU and barcode) is enforced twice:
 * a friendly pre-check for the error message, and the database unique keys as the real guard under concurrent requests.
 */
class VariantService
{
    /** Columns a client may set. Never: id, product_id, signature, search_text, variant_name, last_cost. */
    private const WRITABLE = [
        'sku', 'barcode', 'barcode_aliases', 'position', 'size_width_mm', 'size_height_mm', 'thickness_mm', 'size_label', 'weight_g',
        'glass_type_id', 'tint_id', 'is_glass_only', 'color_finish_id', 'cover_included', 'adjustment', 'heated', 'auto_fold', 'turn_signal',
        'blind_spot_sensor', 'side_camera', 'memory_tilt', 'auto_dimming', 'puddle_light', 'pin_count', 'connector_note', 'supplier_part_no',
        'year_from', 'year_to', 'retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price', 'tax_rate_id', 'reorder_level',
        'reorder_qty', 'max_stock', 'default_location_id', 'track_serial', 'is_bundle', 'is_discontinued', 'is_active', 'internal_note', 'custom',
    ];

    private const BOOLEANS = ['is_glass_only', 'cover_included', 'track_serial', 'is_bundle', 'is_discontinued', 'is_active', ...ProductVariant::FEATURE_FLAGS];

    public function __construct(private readonly AuditService $audit, private readonly CustomFieldService $custom) {}

    /** @param array<string, mixed> $data */
    public function create(Product $product, array $data): ProductVariant
    {
        return DB::transaction(function () use ($product, $data): ProductVariant {
            $attrs = $this->prepare($product, $data, null);
            $this->assertUnique($attrs, null);
            try {
                $variant = new ProductVariant($attrs);
                $variant->product_id = $product->id;
                $variant->save();
            } catch (QueryException $e) {
                throw $this->mapDuplicate($e, $attrs);
            }
            if (! empty($data['generate_barcode']) && $variant->barcode === null) {
                $this->assignGeneratedBarcode($variant);
            }
            $this->refreshDerived($variant);

            return $variant->refresh();
        }, 3); // retried on deadlock (spec: up to 3 attempts)
    }

    /** @param array<string, mixed> $data */
    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        return DB::transaction(function () use ($variant, $data): ProductVariant {
            $variant = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $before = $variant->only(ProductVariant::PRICE_COLUMNS);
            $attrs = $this->prepare($variant->product, $data, $variant);
            $this->assertUnique($attrs, $variant->id);
            if (array_key_exists('is_bundle', $attrs)) {
                $this->assertBundleToggle($variant, (bool) $attrs['is_bundle']);
            }
            try {
                $variant->fill($attrs)->save();
            } catch (QueryException $e) {
                throw $this->mapDuplicate($e, $attrs);
            }
            $this->refreshDerived($variant);
            $after = $variant->only(ProductVariant::PRICE_COLUMNS);
            if ($before !== $after) {
                // FR-304: price history is kept in audit_logs (old/new, user, time).
                $changed = array_keys(array_filter($after, fn ($v, $k) => (string) $v !== (string) $before[$k], ARRAY_FILTER_USE_BOTH));
                $this->audit->log('price_change', 'product_variants', $variant->id, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
            }

            return $variant->refresh();
        });
    }

    public function delete(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            // Frees the signature slot (BR-092) while SKU and barcode stay reserved (BR-093).
            $variant->forceFill(['signature' => $variant->signature.'#'.$variant->id])->saveQuietly();
            $variant->delete();
        });
    }

    /**
     * New variant from an existing one. The overrides must change something that is part of the signature (position, size, features…),
     * otherwise it is a duplicate. The clone gets a fresh SKU and no barcode.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function cloneVariant(ProductVariant $source, array $overrides, bool $copyFitments = true, bool $copyCrossReferences = false): ProductVariant
    {
        $data = array_intersect_key($source->getAttributes(), array_flip(self::WRITABLE));
        foreach (['barcode_aliases', 'custom'] as $json) {
            $data[$json] = $source->{$json};
        }
        unset($data['sku'], $data['barcode']);
        $data['barcode_aliases'] = [];
        $data = array_merge($data, $overrides);

        return DB::transaction(function () use ($source, $data, $copyFitments, $copyCrossReferences): ProductVariant {
            $clone = $this->create($source->product, $data);
            if ($copyFitments) {
                foreach ($source->fitments as $f) {
                    $this->addFitmentRow($clone->id, $f->vehicle_model_id, $f->year_from, $f->year_to, $f->trim, $f->note);
                }
            }
            if ($copyCrossReferences) {
                foreach ($source->crossReferences as $x) {
                    $this->addCrossReference($clone, ['ref_type' => $x->ref_type, 'number' => $x->number, 'brand' => $x->brand, 'note' => $x->note]);
                }
            }
            $this->refreshDerived($clone);

            return $clone->refresh();
        });
    }

    /** Generates the store EAN-13 for a variant that has no barcode yet. */
    public function generateBarcode(ProductVariant $variant): ProductVariant
    {
        return DB::transaction(function () use ($variant): ProductVariant {
            $variant = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            if ($variant->barcode === null) {
                $this->assignGeneratedBarcode($variant);
                $this->refreshDerived($variant);
            }

            return $variant->refresh();
        });
    }

    /** @param array{ref_type?: string, number: string, brand?: string|null, note?: string|null} $data */
    public function addCrossReference(ProductVariant $variant, array $data): OemCrossReference
    {
        $type = $data['ref_type'] ?? 'oem';
        $norm = OemCrossReference::normalize($data['number']);
        if ($norm === '') {
            throw new ApiException('VALIDATION', 422, '', ['number' => ['errors.VALIDATION']]);
        }
        $exists = OemCrossReference::withTrashed()->where(['variant_id' => $variant->id, 'ref_type' => $type, 'number_normalized' => $norm])->first();
        if ($exists && ! $exists->trashed()) {
            throw new ApiException('CONFLICT', 409);
        }
        $ref = $exists ?? new OemCrossReference;
        $ref->fill(['variant_id' => $variant->id, 'ref_type' => $type, 'number' => trim($data['number']), 'number_normalized' => $norm, 'brand' => $data['brand'] ?? null, 'note' => $data['note'] ?? null]);
        $ref->deleted_at = null;
        $ref->save();
        $this->refreshDerived($variant);

        return $ref;
    }

    public function removeCrossReference(OemCrossReference $ref): void
    {
        $variant = ProductVariant::query()->findOrFail($ref->variant_id);
        $ref->delete();
        $this->refreshDerived($variant);
    }

    /** Adds a fitment row; duplicate rows are rejected by the signature unique key. */
    public function addFitmentRow(int $variantId, int $modelId, int $yearFrom, ?int $yearTo, ?string $trim, ?string $note): VehicleFitment
    {
        $sig = VehicleFitment::signatureFor($variantId, $modelId, $yearFrom, $yearTo, $trim);
        $dup = VehicleFitment::query()->where('signature', $sig)->exists();
        if ($dup) {
            throw new ApiException('FITMENT_DUPLICATE', 409);
        }
        try {
            return VehicleFitment::query()->create([
                'variant_id' => $variantId, 'vehicle_model_id' => $modelId, 'year_from' => $yearFrom, 'year_to' => $yearTo,
                'trim' => $trim !== null && trim($trim) !== '' ? trim($trim) : null, 'note' => $note, 'signature' => $sig,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new ApiException('FITMENT_DUPLICATE', 409);
            }
            throw $e;
        }
    }

    /**
     * Bundle components (spec 4.2): the bundle must have is_bundle = true, a component may not be a bundle itself (no nesting) or the bundle.
     */
    public function addBundleItem(ProductVariant $bundle, int $componentId, string $qty): VariantBundleItem
    {
        if (! $bundle->is_bundle) {
            throw new ApiException('BUNDLE_NOT_ENABLED', 422);
        }
        $component = ProductVariant::query()->findOrFail($componentId);
        if ($component->id === $bundle->id) {
            throw new ApiException('BUNDLE_SELF', 422);
        }
        if ($component->is_bundle) {
            throw new ApiException('NESTED_BUNDLE', 422);
        }
        $existing = VariantBundleItem::withTrashed()->where(['bundle_variant_id' => $bundle->id, 'component_variant_id' => $component->id])->first();
        if ($existing && ! $existing->trashed()) {
            throw new ApiException('CONFLICT', 409);
        }
        $item = $existing ?? new VariantBundleItem;
        $item->fill(['bundle_variant_id' => $bundle->id, 'component_variant_id' => $component->id, 'qty' => $qty]);
        $item->deleted_at = null;
        $item->save();

        return $item;
    }

    /** Rebuilds variant_name and search_text (after any change to the variant, its product or its cross references). */
    public function refreshDerived(ProductVariant $variant): void
    {
        $variant->refresh()->unsetRelations();
        $labels = LookupValue::query()->whereIn('id', array_filter([$variant->glass_type_id, $variant->tint_id, $variant->color_finish_id]))->pluck('label_en', 'id');
        $variant->variant_name = VariantSupport::variantName($variant->getAttributes() + [], $labels[$variant->glass_type_id] ?? null, $labels[$variant->tint_id] ?? null, $labels[$variant->color_finish_id] ?? null);
        $variant->search_text = SearchText::variant($variant);
        $variant->saveQuietly();
    }

    private function assertBundleToggle(ProductVariant $variant, bool $becomesBundle): void
    {
        if (! $becomesBundle && VariantBundleItem::query()->where('bundle_variant_id', $variant->id)->exists()) {
            throw new ApiException('BUNDLE_HAS_ITEMS', 422);
        }
        if ($becomesBundle && VariantBundleItem::query()->where('component_variant_id', $variant->id)->exists()) {
            throw new ApiException('NESTED_BUNDLE', 422); // already a component of another bundle
        }
    }

    private function assignGeneratedBarcode(ProductVariant $variant): void
    {
        $code = VariantSupport::ean13ForVariant($variant->id);
        if (ProductVariant::withTrashed()->where('barcode', $code)->where('id', '!=', $variant->id)->exists()) {
            throw new ApiException('BARCODE_DUPLICATE', 409);
        }
        $variant->forceFill(['barcode' => $code])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(Product $product, array $data, ?ProductVariant $existing): array
    {
        $attrs = array_intersect_key($data, array_flip(self::WRITABLE));
        $base = $existing ? $existing->getAttributes() : [];
        foreach (['barcode_aliases', 'custom'] as $json) {
            if ($existing && ! array_key_exists($json, $attrs)) {
                $base[$json] = $existing->{$json};
            }
        }
        if ($existing === null) {
            foreach (self::BOOLEANS as $b) {
                $attrs[$b] = (bool) ($attrs[$b] ?? ($b === 'is_active'));
            }
            if (! array_key_exists('retail_price', $attrs)) {
                throw new ApiException('VALIDATION', 422, '', ['retail_price' => ['errors.REQUIRED']]);
            }
        }
        $merged = array_merge($base, $attrs);
        $merged['product_id'] = $product->id;
        // price floor logic (BR-094 / BR-020): the floor may not exceed the retail price
        if (isset($merged['min_price'], $merged['retail_price']) && (float) $merged['min_price'] > (float) $merged['retail_price']) {
            throw new ApiException('VALIDATION', 422, '', ['min_price' => ['errors.MIN_PRICE_ABOVE_RETAIL']]);
        }
        foreach (ProductVariant::PRICE_COLUMNS as $p) {
            if (isset($merged[$p]) && (float) $merged[$p] < 0) {
                throw new ApiException('VALIDATION', 422, '', [$p => ['errors.VALIDATION']]);
            }
        }
        if (! empty($merged['is_bundle']) && ! empty($merged['track_serial'])) {
            $attrs['track_serial'] = false;
        }

        // size label: auto from dimensions unless typed
        $typedLabel = array_key_exists('size_label', $attrs) ? (string) $attrs['size_label'] : null;
        $dimsChanged = isset($attrs['size_width_mm']) || isset($attrs['size_height_mm']) || array_key_exists('size_width_mm', $attrs) || array_key_exists('size_height_mm', $attrs);
        if ($typedLabel === null && ($existing === null || $dimsChanged)) {
            $typedLabel = '';
        }
        if ($typedLabel !== null) {
            $attrs['size_label'] = VariantSupport::sizeLabel($merged['size_width_mm'] ?? null, $merged['size_height_mm'] ?? null, $typedLabel);
            $merged['size_label'] = $attrs['size_label'];
        }

        // barcode and aliases
        if (array_key_exists('barcode', $attrs)) {
            $attrs['barcode'] = ($attrs['barcode'] !== null && trim((string) $attrs['barcode']) !== '') ? trim((string) $attrs['barcode']) : null;
        }
        if (array_key_exists('barcode_aliases', $attrs)) {
            $aliases = array_values(array_unique(array_filter(array_map(fn ($a) => trim((string) $a), (array) $attrs['barcode_aliases']), fn ($a) => $a !== '')));
            $attrs['barcode_aliases'] = $aliases;
            $merged['barcode_aliases'] = $aliases;
        }

        // SKU: typed, existing, or generated
        $sku = array_key_exists('sku', $attrs) ? strtoupper(trim((string) $attrs['sku'])) : ($existing->sku ?? '');
        if ($sku === '') {
            $sku = $this->uniqueSku(VariantSupport::skuBase($product->code, (string) $merged['position'], $merged['size_label'] ?? null, isset($merged['size_width_mm']) ? (float) $merged['size_width_mm'] : null, isset($merged['size_height_mm']) ? (float) $merged['size_height_mm'] : null), $existing?->id);
        }
        $attrs['sku'] = $sku;

        if (array_key_exists('custom', $attrs) || $existing === null) {
            $attrs['custom'] = $this->custom->normalize('variant', $attrs['custom'] ?? null);
        }
        $attrs['signature'] = VariantSupport::signature($merged);

        return $attrs;
    }

    private function uniqueSku(string $base, ?int $ignoreId): string
    {
        $sku = $base;
        $n = 2;
        while (ProductVariant::withTrashed()->where('sku', $sku)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $sku = $base.'-'.$n++;
        }

        return $sku;
    }

    /** @param array<string, mixed> $attrs */
    private function assertUnique(array $attrs, ?int $ignoreId): void
    {
        $sig = ProductVariant::query()->where('signature', $attrs['signature'])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->first();
        if ($sig) {
            throw $this->duplicate('VARIANT_DUPLICATE', $sig);
        }
        $sku = ProductVariant::withTrashed()->where('sku', $attrs['sku'])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->first();
        if ($sku) {
            throw $this->duplicate('SKU_DUPLICATE', $sku, 'sku');
        }
        $codes = array_values(array_filter(array_merge([$attrs['barcode'] ?? null], $attrs['barcode_aliases'] ?? [])));
        if (isset($attrs['barcode'], $attrs['barcode_aliases']) && in_array($attrs['barcode'], $attrs['barcode_aliases'], true)) {
            throw new ApiException('BARCODE_DUPLICATE', 409, '', ['barcode_aliases' => ['errors.BARCODE_DUPLICATE']]);
        }
        foreach ($codes as $code) {
            $hit = ProductVariant::withTrashed()
                ->where(fn ($q) => $q->where('barcode', $code)->orWhereJsonContains('barcode_aliases', $code))
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->first();
            if ($hit) {
                throw $this->duplicate('BARCODE_DUPLICATE', $hit, 'barcode');
            }
        }
    }

    private function duplicate(string $code, ProductVariant $existing, ?string $field = null): ApiException
    {
        return new ApiException($code, 409, '', $field ? [$field => ["errors.{$code}"]] : [], [
            'existing_variant_id' => $existing->id, 'existing_product_id' => $existing->product_id, 'existing_sku' => $existing->sku,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function mapDuplicate(QueryException $e, array $attrs): QueryException|ApiException
    {
        if (! $this->isDuplicateKey($e)) {
            return $e;
        }
        // Classify by the violated KEY only. The full exception text also contains the INSERT statement, which lists every column.
        $key = $this->violatedKey($e);
        $code = str_contains($key, 'signature') ? 'VARIANT_DUPLICATE' : (str_contains($key, 'barcode') ? 'BARCODE_DUPLICATE' : (str_contains($key, 'sku') ? 'SKU_DUPLICATE' : null));
        if ($code === null) {
            return $e;
        }
        $existing = match ($code) {
            'VARIANT_DUPLICATE' => ProductVariant::query()->where('signature', $attrs['signature'])->first(),
            'SKU_DUPLICATE' => ProductVariant::withTrashed()->where('sku', $attrs['sku'])->first(),
            default => ProductVariant::withTrashed()->where('barcode', $attrs['barcode'] ?? '')->first(),
        };

        return $existing ? $this->duplicate($code, $existing) : new ApiException($code, 409);
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /** "Duplicate entry 'X' for key 'product_variants_sku_unique'" → "product_variants_sku_unique" (MariaDB and MySQL spellings). */
    private function violatedKey(QueryException $e): string
    {
        $driver = (string) ($e->errorInfo[2] ?? '');

        return preg_match("/for key '([^']+)'/", $driver, $m) ? $m[1] : '';
    }
}
