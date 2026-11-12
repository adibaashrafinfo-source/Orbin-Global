<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductImage extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    public const LABELS = ['front', 'back', 'connector', 'package', 'size-chart', 'other'];

    protected $fillable = ['product_id', 'variant_id', 'storage_path', 'url', 'thumb_url', 'label', 'alt_text', 'sort_order', 'is_primary'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_primary' => 'boolean'];
    }
}
