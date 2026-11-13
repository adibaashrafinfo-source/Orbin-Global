<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportPreset extends Model
{
    protected $fillable = ['user_id', 'report_id', 'name', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
