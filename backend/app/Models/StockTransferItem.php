<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransferItem extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = ['transfer_id', 'variant_id', 'qty_sent', 'qty_received', 'unit_cost', 'note'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
