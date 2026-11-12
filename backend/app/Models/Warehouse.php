<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['branch_id', 'code', 'name', 'kind', 'is_default', 'is_sellable', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_sellable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(StorageLocation::class);
    }
}
