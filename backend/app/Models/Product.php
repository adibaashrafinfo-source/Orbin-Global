<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mirror family. Stock, price, SKU and barcode live on variants.
 *
 * @property array<int, string>|null $tags
 * @property array<string, mixed>|null $custom
 */
class Product extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    public const MIRROR_TYPES = ['side_mirror', 'interior_mirror', 'mirror_glass', 'mirror_cover', 'mirror_signal_lamp', 'mirror_motor', 'blind_spot_mirror', 'accessory'];

    protected $fillable = [
        'code', 'name_en', 'name_bn', 'mirror_type', 'category_id', 'brand_id', 'quality_grade_id', 'origin_country_id',
        'short_description', 'description', 'warranty_months', 'return_window_days', 'tax_rate_id', 'unit_id', 'tags',
        'primary_image_url', 'custom', 'is_active', 'search_text',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array', 'custom' => 'array', 'is_active' => 'boolean',
            'warranty_months' => 'integer', 'return_window_days' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'brand_id');
    }

    public function qualityGrade(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class, 'quality_grade_id');
    }

    public function originCountry(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class, 'origin_country_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }
}
