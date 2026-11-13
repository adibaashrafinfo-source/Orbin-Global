<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalePayment extends Model
{
    use HasBlame, SoftDeletes;

    public const METHODS = ['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer'];

    public const MOBILE_MONEY = ['bkash', 'nagad', 'rocket'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'verified_at' => 'datetime', 'transfer_date' => 'date:Y-m-d'];
    }
}
