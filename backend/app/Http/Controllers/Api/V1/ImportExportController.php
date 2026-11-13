<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Catalog\ExportService;
use App\Services\Catalog\ImportService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FR-204: template download, dry-run with row-level errors, all-or-nothing commit; streamed product export. */
class ImportExportController extends Controller
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly ExportService $exports,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
    ) {}

    public function template(Request $request, string $kind): Response
    {
        $this->authorizeKind($request, $kind);
        $format = $request->input('format') === 'xlsx' ? 'xlsx' : 'csv';
        [$content, $name] = $this->imports->template($kind, $format);

        return response($content, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function dryRun(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string'], 'file' => ['required', 'file', 'max:10240']]);
        $this->authorizeKind($request, $data['kind']);
        /** @var User $user */
        $user = $request->user();
        $result = $this->imports->dryRun($data['kind'], $request->file('file'), $user->id);

        return response()->json(['data' => $result]);
    }

    public function commit(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string'], 'token' => ['required', 'string', 'size:40']]);
        $this->authorizeKind($request, $data['kind']);
        /** @var User $user */
        $user = $request->user();
        $result = $this->imports->commit($data['token'], $user->id, $data['kind']);
        $this->audit->log('import', 'imports', null, null, ['kind' => $data['kind'], 'created' => $result['created'], 'updated' => $result['updated'], 'rows' => $result['total_rows']]);

        return response()->json(['data' => $result]);
    }

    public function exportProducts(Request $request): StreamedResponse
    {
        $format = $request->input('format') === 'xlsx' ? 'xlsx' : 'csv';
        $search = $request->filled('search') ? (string) $request->input('search') : null;
        $this->audit->log('export', 'products', null, null, ['format' => $format, 'search' => $search]);
        $name = 'orbin-products-'.now('Asia/Dhaka')->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($format, $search): void {
            $this->exports->stream($format, $search);
        }, $name, ['Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8']);
    }

    /** Makes and models need masterdata.manage; products and fitments need products.import (products also pricing.manage); opening stock sets cost, so it needs the approval and cost permissions. */
    private function authorizeKind(Request $request, string $kind): void
    {
        if (! in_array($kind, ImportService::KINDS, true)) {
            throw ApiException::notFound();
        }
        /** @var User $user */
        $user = $request->user();
        $needed = match ($kind) {
            'vehicle-makes', 'vehicle-models' => ['masterdata.manage'],
            'products' => ['products.import', 'pricing.manage'],
            'opening-stock' => ['inventory.adjust.approve', 'products.view_cost'],
            default => ['products.import'],
        };
        foreach ($needed as $permission) {
            if (! $this->permissions->userHas($user, $permission)) {
                throw ApiException::forbidden();
            }
        }
    }
}
