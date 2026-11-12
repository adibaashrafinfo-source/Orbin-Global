<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sellable and stockable unit. Uniqueness by BR-092 through the `signature` column.
 *
 * @property array<int, string>|null $barcode_aliases
 */
class ProductVariant extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    public const POSITIONS = ['left', 'right', 'interior', 'pair', 'universal'];

    public const ADJUSTMENTS = ['manual', 'electric', 'cable'];

    public const FEATURE_FLAGS = ['heated', 'auto_fold', 'turn_signal', 'blind_spot_sensor', 'side_camera', 'memory_tilt', 'auto_dimming', 'puddle_light'];

    /** Prices and cost: written only with pricing.manage; cost read only with products.view_cost. */
    public const PRICE_COLUMNS = ['retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price'];

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'barcode_aliases', 'position', 'size_width_mm', 'size_height_mm', 'thickness_mm', 'size_label',
        'weight_g', 'glass_type_id', 'tint_id', 'is_glass_only', 'color_finish_id', 'cover_included', 'adjustment',
        'heated', 'auto_fold', 'turn_signal', 'blind_spot_sensor', 'side_camera', 'memory_tilt', 'auto_dimming', 'puddle_light',
        'pin_count', 'connector_note', 'supplier_part_no', 'year_from', 'year_to', 'last_cost', 'retail_price', 'wholesale_price',
        'dealer_price', 'mrp', 'min_price', 'tax_rate_id', 'reorder_level', 'reorder_qty', 'max_stock', 'default_location_id',
        'track_serial', 'is_bundle', 'is_discontinued', 'is_active', 'internal_note', 'custom', 'variant_name', 'signature', 'search_text',
    ];

    protected function casts(): array
    {
        $bools = ['is_glass_only', 'cover_included', 'track_serial', 'is_bundle', 'is_discontinued', 'is_active', ...self::FEATURE_FLAGS];

        return array_merge(array_fill_keys($bools, 'boolean'), [
            'barcode_aliases' => 'array', 'custom' => 'array', 'pin_count' => 'integer', 'year_from' => 'integer', 'year_to' => 'integer',
            'retail_price' => 'decimal:2', 'wholesale_price' => 'decimal:2', 'dealer_price' => 'decimal:2', 'mrp' => 'decimal:2',
            'min_price' => 'decimal:2', 'last_cost' => 'decimal:2',
            'size_width_mm' => 'decimal:1', 'size_height_mm' => 'decimal:1', 'thickness_mm' => 'decimal:1', 'weight_g' => 'decimal:1',
            'reorder_level' => 'decimal:3', 'reorder_qty' => 'decimal:3', 'max_stock' => 'decimal:3',
        ]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function glassType(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class, 'glass_type_id');
    }

    public function tint(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class, 'tint_id');
    }

    public function colorFinish(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class, 'color_finish_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function crossReferences(): HasMany
    {
        return $this->hasMany(OemCrossReference::class, 'variant_id')->orderBy('id');
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(VehicleFitment::class, 'variant_id')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'variant_id')->orderBy('sort_order')->orderBy('id');
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(VariantBundleItem::class, 'bundle_variant_id');
    }
}
