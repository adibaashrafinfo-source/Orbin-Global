<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Written by AuditService only.
 */
class AuditLog extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'occurred_at', 'actor_id', 'action', 'entity_table', 'entity_id', 'before', 'after', 'reason', 'approver_id',
        'ip', 'user_agent', 'request_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
