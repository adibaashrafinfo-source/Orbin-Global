<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartyPayment extends Model
{
    use HasBlame, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_date' => 'date:Y-m-d'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PartyPaymentAllocation::class, 'payment_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }
}
