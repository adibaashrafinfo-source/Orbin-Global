<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ReportPreset;
use App\Reports\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Saved filter presets, private to each user (FR-1100). */
class ReportPresetController extends Controller
{
    public function __construct(private readonly ReportRegistry $registry) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['report_id' => ['sometimes', 'string', 'max:60']]);
        $rows = ReportPreset::query()->where('user_id', $request->user()->id)->when($v['report_id'] ?? null, fn ($q, $id) => $q->where('report_id', $id))->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->row($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'report_id' => ['required', 'string', 'max:60'], 'name' => ['required', 'string', 'min:1', 'max:80'],
            'filters' => ['required', 'array'], 'filters.range' => ['sometimes', 'nullable', Rule::in(['today', 'week', 'month', 'last_month', 'custom'])],
            'filters.from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'filters.to' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'filters.branch_id' => ['sometimes', 'nullable', 'integer'],
            'filters.warehouse_id' => ['sometimes', 'nullable', 'integer'], 'filters.group' => ['sometimes', 'nullable', 'string', 'max:20'], 'filters.compare' => ['sometimes', 'boolean'],
        ]);
        $def = $this->registry->find($v['report_id']) ?? throw ApiException::notFound();
        if (! $this->registry->canOpen($request->user(), $def)) {
            throw ApiException::forbidden();
        }
        // Saving under an existing name replaces it, so "update" and "save" are the same gesture.
        $p = ReportPreset::query()->updateOrCreate(['user_id' => $request->user()->id, 'report_id' => $v['report_id'], 'name' => trim($v['name'])], ['filters' => $v['filters']]);

        return response()->json(['data' => $this->row($p)], 201);
    }

    public function destroy(Request $request, ReportPreset $preset): JsonResponse
    {
        if ($preset->user_id !== $request->user()->id) {
            throw ApiException::forbidden();
        }
        $preset->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function row(ReportPreset $p): array
    {
        return ['id' => $p->id, 'report_id' => $p->report_id, 'name' => $p->name, 'filters' => $p->filters];
    }
}
