<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseExtraCost extends Model
{
    use HasBlame, SoftDeletes;

    public const TYPES = ['freight', 'customs_duty', 'clearing_forwarding', 'local_transport', 'insurance', 'other'];

    protected $fillable = ['grn_id', 'cost_type', 'description', 'amount', 'paid_to', 'account_id'];
}
