<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleReturn extends Model
{
    use HasBlame, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['return_date' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'return_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
