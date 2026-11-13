<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundTransfer extends Model
{
    use HasBlame, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['transfer_date' => 'date:Y-m-d'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'from_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'to_account_id');
    }
}
