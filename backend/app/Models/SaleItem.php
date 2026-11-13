<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleItem extends Model
{
    use HasBlame, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['spec_snapshot' => 'array', 'warranty_until' => 'date:Y-m-d'];
    }
}
