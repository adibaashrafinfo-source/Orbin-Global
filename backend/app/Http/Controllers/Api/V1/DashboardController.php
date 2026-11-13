<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The role-aware dashboard. Every signed-in person may ask; the widgets they get depend on their permissions. */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function show(Request $request): JsonResponse
    {
        $v = $request->validate(['range' => ['sometimes', 'in:today,7d,30d,month,custom'], 'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json(['data' => $this->dashboard->build($request->user(), $v['range'] ?? 'today', $v['from'] ?? null, $v['to'] ?? null)]);
    }
}
