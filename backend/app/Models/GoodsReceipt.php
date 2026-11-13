<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['branch_id', 'grn_no', 'warehouse_id', 'supplier_id', 'po_id', 'supplier_invoice_no', 'supplier_invoice_date', 'received_date', 'status', 'allocation_basis', 'items_total', 'extra_cost_total', 'grand_total', 'paid_total', 'note', 'posted_at'];

    protected function casts(): array
    {
        return ['received_date' => 'date:Y-m-d', 'supplier_invoice_date' => 'date:Y-m-d', 'posted_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'grn_id');
    }

    public function extraCosts(): HasMany
    {
        return $this->hasMany(PurchaseExtraCost::class, 'grn_id');
    }
}
