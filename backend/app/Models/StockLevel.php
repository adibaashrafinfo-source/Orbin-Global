<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockLevel extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = ['variant_id', 'warehouse_id', 'location_id', 'qty_on_hand', 'qty_reserved', 'avg_cost', 'last_movement_at'];

    protected function casts(): array
    {
        return ['last_movement_at' => 'datetime'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
