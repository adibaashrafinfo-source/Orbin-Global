<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturn extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['branch_id', 'return_no', 'supplier_id', 'grn_id', 'warehouse_id', 'status', 'return_date', 'reason', 'total', 'posted_at'];

    protected function casts(): array
    {
        return ['return_date' => 'date:Y-m-d', 'posted_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class, 'return_id');
    }
}
