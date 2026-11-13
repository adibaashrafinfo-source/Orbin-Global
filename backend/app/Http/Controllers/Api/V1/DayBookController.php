<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Finance\DayBookService;
use App\Support\Branches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-1006 */
class DayBookController extends Controller
{
    public function __construct(private readonly DayBookService $book) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => ['sometimes', 'nullable', 'integer'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $today = Branches::today();
        $out = $this->book->run(['from' => $v['from'] ?? $today, 'to' => $v['to'] ?? $today, 'account_id' => $v['account_id'] ?? null, 'page' => $v['page'] ?? 1, 'per_page' => $v['per_page'] ?? 50]);

        return response()->json(['data' => ['accounts' => $out['accounts'], 'totals' => $out['totals'], 'rows' => $out['rows']], 'meta' => $out['meta']]);
    }
}
