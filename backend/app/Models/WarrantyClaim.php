<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarrantyClaim extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
