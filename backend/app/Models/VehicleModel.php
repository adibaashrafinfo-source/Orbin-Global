<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleModel extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['make_id', 'name', 'generation_code', 'body_type_id', 'year_from', 'year_to', 'is_active'];

    protected function casts(): array
    {
        return ['year_from' => 'integer', 'year_to' => 'integer', 'is_active' => 'boolean'];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function bodyType(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class, 'body_type_id');
    }
}
