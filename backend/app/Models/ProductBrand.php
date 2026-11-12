<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductBrand extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['name', 'country_id', 'logo_url', 'website', 'is_oem', 'is_active'];

    protected function casts(): array
    {
        return ['is_oem' => 'boolean', 'is_active' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class, 'country_id');
    }
}
