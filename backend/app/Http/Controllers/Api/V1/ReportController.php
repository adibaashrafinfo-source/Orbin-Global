<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Reports\ReportContext;
use App\Reports\ReportDef;
use App\Reports\ReportExporter;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** FR-1100: the report catalog, one report at a time with filters and compare, and its exports. */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ReportExporter $exporter,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => array_map(fn (ReportDef $d) => $d->summary(), $this->registry->visibleTo($request->user()))]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $def = $this->open($request, $report);
        $v = $request->validate($this->rules() + [
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:40'], 'dir' => ['sometimes', 'in:asc,desc'], 'compare' => ['sometimes', 'boolean'],
        ]);
        $out = $this->runner->run($def, ReportFilters::fromInput($v), ReportContext::for($request->user()), (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 50), $v['sort'] ?? null, $v['dir'] ?? 'asc', (bool) ($v['compare'] ?? false));

        return response()->json(['data' => $def->summary() + ['columns' => $out['columns'], 'rows' => $out['rows'], 'totals' => $out['totals'], 'compare' => $out['compare']], 'meta' => $out['meta']]);
    }

    public function export(Request $request, string $report): Response
    {
        $def = $this->open($request, $report);
        $v = $request->validate($this->rules() + ['format' => ['required', 'in:csv,xlsx,pdf'], 'inline' => ['sometimes', 'boolean']]);
        $f = ReportFilters::fromInput($v);
        $ctx = ReportContext::for($request->user());
        $this->audit->log('export', 'reports', null, null, ['report' => $def->id, 'format' => $v['format'], 'filters' => $f->toArray()]);

        return match ($v['format']) {
            'csv' => $this->exporter->csv($def, $f, $ctx),
            'xlsx' => $this->exporter->xlsx($def, $f, $ctx),
            default => $this->exporter->pdf($def, $f, $ctx, (bool) ($v['inline'] ?? false)),
        };
    }

    private function open(Request $request, string $id): ReportDef
    {
        $def = $this->registry->find($id) ?? throw ApiException::notFound();
        if (! $this->registry->canOpen($request->user(), $def)) {
            throw ApiException::forbidden();
        }

        return $def;
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'branch_id' => ['sometimes', 'nullable', 'integer'], 'warehouse_id' => ['sometimes', 'nullable', 'integer'], 'group' => ['sometimes', 'nullable', 'string', 'max:20'],
            'variant_id' => ['sometimes', 'nullable', 'integer'], 'search' => ['sometimes', 'nullable', 'string', 'max:100'], 'account_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
