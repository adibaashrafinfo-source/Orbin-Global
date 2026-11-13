<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustment extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['branch_id', 'adj_no', 'warehouse_id', 'status', 'reason_id', 'note', 'requires_approval', 'approved_by', 'approved_at', 'posted_at', 'total_value'];

    protected function casts(): array
    {
        return ['requires_approval' => 'boolean', 'approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class, 'reason_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'adjustment_id');
    }
}
