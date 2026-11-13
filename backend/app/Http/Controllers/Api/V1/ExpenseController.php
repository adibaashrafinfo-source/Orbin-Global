<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ExpenseRequest;
use App\Http\Requests\Finance\ReasonRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** FR-1005 */
class ExpenseController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Expense::query()->with(['category', 'account'])->orderByDesc('expense_date')->orderByDesc('id');
        foreach (['status', 'category_id', 'account_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }
        if ($request->filled('filter.from')) {
            $query->where('expense_date', '>=', $request->input('filter.from'));
        }
        if ($request->filled('filter.to')) {
            $query->where('expense_date', '<=', $request->input('filter.to'));
        }
        if ($q = trim((string) $request->input('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('expense_no', 'like', $like)->orWhere('payee', 'like', $like)->orWhere('note', 'like', $like));
        }

        return $this->paged($query, $request, ExpenseResource::class);
    }

    public function show(Expense $expense): ExpenseResource
    {
        return new ExpenseResource($expense->load(['category', 'account']));
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        return (new ExpenseResource($this->expenses->create($request->user(), $request->validated())->load(['category', 'account'])))->response()->setStatusCode(201);
    }

    public function approve(Request $request, Expense $expense): ExpenseResource
    {
        return new ExpenseResource($this->expenses->approve($request->user(), $expense)->load(['category', 'account']));
    }

    public function reject(ReasonRequest $request, Expense $expense): ExpenseResource
    {
        return new ExpenseResource($this->expenses->reject($request->user(), $expense, $request->validated('reason'))->load(['category', 'account']));
    }

    public function void(ReasonRequest $request, Expense $expense): ExpenseResource
    {
        return new ExpenseResource($this->expenses->void($request->user(), $expense, $request->validated('reason'))->load(['category', 'account']));
    }
}
