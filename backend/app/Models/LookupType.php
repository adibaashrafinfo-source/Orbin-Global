<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LookupType extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['key', 'name_en', 'name_bn', 'description', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(LookupValue::class, 'type_id')->orderBy('sort_order');
    }
}
