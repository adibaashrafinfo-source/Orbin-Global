<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturnItem extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = ['return_id', 'variant_id', 'qty', 'unit_cost'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
