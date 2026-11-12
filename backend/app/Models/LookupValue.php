<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LookupValue extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['type_id', 'code', 'label_en', 'label_bn', 'sort_order', 'meta', 'is_active'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LookupType::class, 'type_id');
    }
}
