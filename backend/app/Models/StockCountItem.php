<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockCountItem extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = ['count_id', 'variant_id', 'system_qty', 'counted_qty', 'unit_cost', 'note'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
