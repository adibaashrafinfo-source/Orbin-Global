<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialAccount extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    public const TYPES = ['cash', 'bkash', 'nagad', 'rocket', 'bank'];

    protected $fillable = ['branch_id', 'code', 'name', 'account_type', 'provider', 'account_number', 'bank_name', 'bank_branch', 'opening_balance', 'opening_date', 'is_default_for_method', 'is_active', 'note'];

    protected function casts(): array
    {
        return ['is_default_for_method' => 'boolean', 'is_active' => 'boolean', 'opening_date' => 'date:Y-m-d'];
    }
}
