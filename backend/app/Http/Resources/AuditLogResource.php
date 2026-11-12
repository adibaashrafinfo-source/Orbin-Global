<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? ['id' => $this->actor->id, 'full_name' => $this->actor->full_name, 'role' => $this->actor->role] : null),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? ['id' => $this->approver->id, 'full_name' => $this->approver->full_name] : null),
            'action' => $this->action,
            'entity_table' => $this->entity_table,
            'entity_id' => $this->entity_id,
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'ip' => $this->ip,
            'request_id' => $this->request_id,
        ];
    }
}
