<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The only writer of audit_logs (NFR-011). Every mutation of money, stock, price, permission, void or override
 * goes through here, either via the Auditable model trait or explicit calls from services/controllers.
 */
class AuditService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        string $entityTable,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?int $approverId = null,
        ?int $actorId = null,
    ): AuditLog {
        $request = app()->bound('request') ? app('request') : null;
        $actorId ??= Auth::id();

        return AuditLog::query()->create([
            'occurred_at' => now(),
            'actor_id' => $actorId,
            'created_by' => $actorId,
            'action' => $action,
            'entity_table' => $entityTable,
            'entity_id' => $entityId !== null ? (int) $entityId : null,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'approver_id' => $approverId,
            'ip' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'request_id' => app()->bound('request_id') ? (string) app('request_id') : null,
        ]);
    }
}
