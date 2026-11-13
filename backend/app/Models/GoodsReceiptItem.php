<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceiptItem extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = ['grn_id', 'po_item_id', 'variant_id', 'qty_received', 'qty_damaged', 'unit_cost', 'allocated_extra', 'landed_unit_cost', 'line_total'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'grn_id');
    }
}
