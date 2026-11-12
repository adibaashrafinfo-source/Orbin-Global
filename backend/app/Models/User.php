<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $full_name
 * @property string|null $phone
 * @property string $email
 * @property string $password_hash
 * @property string $role
 * @property int|null $default_branch_id
 * @property array<int>|null $branch_ids
 * @property string|null $override_pin_hash
 * @property int $pin_failed_attempts
 * @property CarbonImmutable|null $pin_locked_until
 * @property string|null $avatar_url
 * @property string $language
 * @property bool $is_active
 * @property bool $must_change_password
 * @property CarbonImmutable|null $last_login_at
 */
class User extends Authenticatable
{
    use Auditable, HasBlame, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'full_name', 'phone', 'email', 'role', 'default_branch_id', 'branch_ids', 'avatar_url', 'language',
        'is_active', 'must_change_password',
    ];

    protected $hidden = ['password_hash', 'override_pin_hash', 'remember_token'];

    /** Never written to audit_logs in clear text. */
    protected array $auditHidden = ['password_hash', 'override_pin_hash'];

    protected function casts(): array
    {
        return [
            'branch_ids' => 'array',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'pin_failed_attempts' => 'integer',
            'pin_locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** Laravel's auth expects getAuthPassword(); our column is password_hash. */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function hasOverridePin(): bool
    {
        return $this->override_pin_hash !== null;
    }
}
