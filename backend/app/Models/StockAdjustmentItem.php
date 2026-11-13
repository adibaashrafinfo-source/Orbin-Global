<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustmentItem extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = ['adjustment_id', 'variant_id', 'qty_delta', 'unit_cost', 'is_breakage', 'supplier_id', 'photo_attachment_id', 'note'];

    protected function casts(): array
    {
        return ['is_breakage' => 'boolean'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
