<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\StampsCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use AppendOnly, StampsCreator;

    public const UPDATED_AT = null;

    public const TYPES = ['opening', 'purchase_receipt', 'purchase_return', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out', 'transfer_out', 'transfer_in', 'breakage', 'damage', 'count_correction'];

    protected $fillable = ['created_by', 'branch_id', 'variant_id', 'warehouse_id', 'movement_type', 'qty_delta', 'unit_cost', 'balance_after', 'ref_table', 'ref_id', 'ref_no', 'reason', 'note', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
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
