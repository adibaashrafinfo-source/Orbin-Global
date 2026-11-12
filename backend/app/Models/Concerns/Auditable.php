<?php

namespace App\Models\Concerns;

use App\Services\AuditService;

/**
 * Writes audit_logs rows for create / update / delete through AuditService (CLAUDE.md §4, NFR-011).
 * Models may define $auditHidden (attributes never written to before/after) and auditAction() overrides.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model): void {
            app(AuditService::class)->log(
                action: $model->auditActionName('create'),
                entityTable: $model->getTable(),
                entityId: $model->getKey(),
                before: null,
                after: $model->auditSnapshot($model->getAttributes()),
            );
        });
        static::updated(function ($model): void {
            $changes = $model->getChanges();
            unset($changes['updated_at'], $changes['updated_by']);
            if ($changes === []) {
                return;
            }
            $before = array_intersect_key($model->getOriginal(), $changes);
            app(AuditService::class)->log(
                action: $model->auditActionName('update'),
                entityTable: $model->getTable(),
                entityId: $model->getKey(),
                before: $model->auditSnapshot($before),
                after: $model->auditSnapshot($changes),
            );
        });
        static::deleted(function ($model): void {
            app(AuditService::class)->log(
                action: $model->auditActionName('delete'),
                entityTable: $model->getTable(),
                entityId: $model->getKey(),
                before: $model->auditSnapshot($model->getOriginal()),
                after: null,
            );
        });
    }

    /** @param  array<string, mixed>  $attributes */
    public function auditSnapshot(array $attributes): array
    {
        $hidden = property_exists($this, 'auditHidden') ? $this->auditHidden : [];
        foreach ($hidden as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '[redacted]';
            }
        }
        unset($attributes['remember_token']);

        return $attributes;
    }

    public function auditActionName(string $default): string
    {
        return $default;
    }
}
