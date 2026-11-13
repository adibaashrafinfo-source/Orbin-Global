<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\StampsCreator;
use Illuminate\Database\Eloquent\Model;

/** Append-only: which invoice, goods receipt or opening balance a payment settled, and how much. */
class PartyPaymentAllocation extends Model
{
    use AppendOnly, StampsCreator;

    public const UPDATED_AT = null;

    protected $fillable = ['created_by', 'payment_id', 'doc_table', 'doc_id', 'amount'];
}
