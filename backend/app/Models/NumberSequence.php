<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $key
 * @property int|null $branch_id
 * @property string $prefix
 * @property string|null $date_token
 * @property int $padding
 * @property string $reset_policy
 * @property string $period_key
 * @property int $next_value
 */
class NumberSequence extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['key', 'branch_id', 'prefix', 'date_token', 'padding', 'reset_policy', 'period_key', 'next_value'];

    protected function casts(): array
    {
        return ['padding' => 'integer', 'next_value' => 'integer'];
    }
}
