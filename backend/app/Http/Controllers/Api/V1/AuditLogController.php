<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FR-108 Audit log: filter, paginate, streamed CSV export. */
class AuditLogController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $query = $this->filtered($request)->with(['actor', 'approver'])->orderByDesc('occurred_at')->orderByDesc('id');
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = $query->paginate($perPage);

        return AuditLogResource::collection($page->items())->additional([
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** Streams CSV in chunks so exports stay inside shared-hosting memory limits. */
    public function export(AuditLogIndexRequest $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('actor')->orderBy('id');
        $this->audit->log('export', 'audit_logs', null, null, ['filters' => $request->input('filter', [])]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['id', 'occurred_at', 'actor', 'action', 'entity_table', 'entity_id', 'before', 'after', 'reason', 'ip', 'request_id']);
            $query->chunkById(500, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id,
                        $row->occurred_at?->toIso8601String(),
                        $row->actor?->full_name,
                        $row->action,
                        $row->entity_table,
                        $row->entity_id,
                        json_encode($row->before, JSON_UNESCAPED_UNICODE),
                        json_encode($row->after, JSON_UNESCAPED_UNICODE),
                        $row->reason,
                        $row->ip,
                        $row->request_id,
                    ]);
                }
                flush();
            });
            fclose($out);
        }, 'audit-log-'.now('Asia/Dhaka')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<AuditLog> */
    private function filtered(AuditLogIndexRequest $request): Builder
    {
        $query = AuditLog::query();
        $f = (array) $request->input('filter', []);
        if (! empty($f['action'])) {
            $query->where('action', $f['action']);
        }
        if (! empty($f['entity_table'])) {
            $query->where('entity_table', $f['entity_table']);
        }
        if (! empty($f['entity_id'])) {
            $query->where('entity_id', (int) $f['entity_id']);
        }
        if (! empty($f['actor_id'])) {
            $query->where('actor_id', (int) $f['actor_id']);
        }
        if (! empty($f['from'])) {
            $query->where('occurred_at', '>=', now()->parse($f['from'], 'Asia/Dhaka')->startOfDay()->utc());
        }
        if (! empty($f['to'])) {
            $query->where('occurred_at', '<=', now()->parse($f['to'], 'Asia/Dhaka')->endOfDay()->utc());
        }
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(fn ($q) => $q->where('entity_table', 'like', "%{$search}%")->orWhere('action', 'like', "%{$search}%")->orWhere('reason', 'like', "%{$search}%"));
        }

        return $query;
    }
}
