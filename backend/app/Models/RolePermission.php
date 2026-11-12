<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RolePermission extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['role', 'permission_key', 'allowed'];

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }

    public function auditActionName(string $default): string
    {
        return 'permission_change';
    }
}
