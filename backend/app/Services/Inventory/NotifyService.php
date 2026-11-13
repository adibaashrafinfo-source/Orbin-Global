<?php

namespace App\Services\Inventory;

use App\Models\Notification;

/** Bell entries for roles (low stock, approvals waiting, short transfers). One open entry per type and record. */
class NotifyService
{
    /** @param  array<int, string>  $roles */
    public function roles(array $roles, string $type, string $severity, string $title, ?string $body = null, ?string $entityTable = null, ?int $entityId = null): void
    {
        foreach ($roles as $role) {
            $exists = Notification::query()->where(['role' => $role, 'type' => $type, 'entity_table' => $entityTable, 'entity_id' => $entityId, 'is_read' => false])->exists();
            if ($exists) {
                continue;
            }
            Notification::query()->create(['role' => $role, 'type' => $type, 'severity' => $severity, 'title' => $title, 'body' => $body, 'entity_table' => $entityTable, 'entity_id' => $entityId]);
        }
    }
}
