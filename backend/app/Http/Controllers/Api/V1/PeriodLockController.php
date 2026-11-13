<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PeriodLockRequest;
use App\Services\Inventory\PeriodLockService;
use Illuminate\Http\JsonResponse;

/** FR-1009 */
class PeriodLockController extends Controller
{
    public function __construct(private readonly PeriodLockService $period) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => ['lock_date' => $this->period->current()]]);
    }

    public function update(PeriodLockRequest $request): JsonResponse
    {
        return response()->json(['data' => ['lock_date' => $this->period->lock($request->user(), $request->validated('lock_date'))]]);
    }
}
