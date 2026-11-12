<?php

namespace App\Models;

use App\Casts\JsonValue;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $key
 * @property mixed $value
 */
class AppSetting extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['key', 'value', 'group_name', 'description'];

    protected function casts(): array
    {
        return ['value' => JsonValue::class];
    }
}
