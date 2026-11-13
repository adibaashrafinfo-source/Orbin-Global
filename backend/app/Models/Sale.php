<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Invoice header. Written only by SaleService; a completed invoice is immutable (BR-050). */
class Sale extends Model
{
    use HasBlame, SoftDeletes;

    protected $guarded = [];

    /** BR-050: once an invoice is completed only these may change; everything else is a void or a return. */
    private const STILL_CHANGEABLE = ['status', 'void_reason', 'voided_by', 'voided_at', 'pdf_path', 'pdf_generated_at', 'reprint_count', 'paid_total', 'due_total', 'payment_status', 'updated_at', 'updated_by', 'deleted_at'];

    protected static function booted(): void
    {
        static::updating(function (self $sale): void {
            if (in_array($sale->getOriginal('status'), ['draft', 'held'], true)) {
                return;
            }
            $illegal = array_diff(array_keys($sale->getDirty()), self::STILL_CHANGEABLE);
            if ($illegal !== []) {
                throw new \LogicException('A completed invoice is immutable (BR-050); tried to change '.implode(', ', $illegal).'.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'customer_snapshot' => 'array', 'company_snapshot' => 'array', 'vehicle_snapshot' => 'array', 'held_payload' => 'array',
            'sale_date' => 'datetime', 'due_date' => 'date:Y-m-d', 'pdf_generated_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('line_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }
}
