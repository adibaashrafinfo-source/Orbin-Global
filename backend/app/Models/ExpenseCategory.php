<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    /** Posted to by fund transfers for their fee; cannot be deactivated or renamed away from the system. */
    public const TRANSFER_FEE = 'transfer_fee';

    protected $fillable = ['parent_id', 'name_en', 'name_bn', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
