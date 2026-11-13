<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    public const STATUSES = ['draft', 'approved', 'partially_received', 'received', 'closed', 'cancelled'];

    protected $fillable = ['branch_id', 'po_no', 'supplier_id', 'status', 'order_date', 'expected_date', 'subtotal', 'tax_total', 'extra_total', 'grand_total', 'note', 'approved_by', 'approved_at', 'pdf_path'];

    protected function casts(): array
    {
        return ['order_date' => 'date:Y-m-d', 'expected_date' => 'date:Y-m-d', 'approved_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id');
    }
}
