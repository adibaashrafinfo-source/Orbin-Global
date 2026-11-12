<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = [
        'user_id', 'role', 'type', 'severity', 'title', 'body', 'entity_table', 'entity_id', 'is_read', 'read_at',
    ];

    protected function casts(): array
    {
        return ['is_read' => 'boolean', 'read_at' => 'immutable_datetime'];
    }
}
