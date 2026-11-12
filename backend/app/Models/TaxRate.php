<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxRate extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['code', 'name', 'rate_percent', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['rate_percent' => 'decimal:2', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
