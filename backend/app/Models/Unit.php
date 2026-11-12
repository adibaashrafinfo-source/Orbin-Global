<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['code', 'name_en', 'name_bn', 'is_pair_unit', 'is_active'];

    protected function casts(): array
    {
        return ['is_pair_unit' => 'boolean', 'is_active' => 'boolean'];
    }
}
