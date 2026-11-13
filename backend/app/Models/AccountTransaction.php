<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\StampsCreator;
use Illuminate\Database\Eloquent\Model;

/** Append-only: every money movement in or out of a financial account. */
class AccountTransaction extends Model
{
    use AppendOnly, StampsCreator;

    public const UPDATED_AT = null;

    protected $fillable = ['created_by', 'branch_id', 'account_id', 'direction', 'amount', 'txn_date', 'occurred_at', 'ref_type', 'ref_id', 'ref_no', 'counterparty_type', 'counterparty_id', 'description'];

    protected function casts(): array
    {
        return ['txn_date' => 'date:Y-m-d', 'occurred_at' => 'datetime'];
    }
}
