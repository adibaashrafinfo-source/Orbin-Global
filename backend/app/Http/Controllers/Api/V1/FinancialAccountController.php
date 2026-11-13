<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\AccountAdjustRequest;
use App\Http\Requests\Finance\AccountRequest;
use App\Http\Resources\FinancialAccountResource;
use App\Models\FinancialAccount;
use App\Services\Finance\AccountLedgerService;
use App\Services\Finance\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** FR-1003 */
class FinancialAccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts, private readonly AccountLedgerService $ledger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        FinancialAccountResource::$balances = $this->ledger->balances();
        $query = FinancialAccount::query()->orderBy('account_type')->orderBy('id');
        if ($request->has('filter.is_active') && $request->input('filter.is_active') !== '') {
            $query->where('is_active', filter_var($request->input('filter.is_active'), FILTER_VALIDATE_BOOL));
        }

        return FinancialAccountResource::collection($query->get());
    }

    public function show(FinancialAccount $account): FinancialAccountResource
    {
        return new FinancialAccountResource($account);
    }

    public function transactions(Request $request, FinancialAccount $account): JsonResponse
    {
        $request->validate(['from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = (int) $request->input('page', 1);
        $per = (int) $request->input('per_page', 25);
        $out = $this->ledger->transactions($account->id, $request->input('from'), $request->input('to'), $page, $per);

        return response()->json(['data' => $out['rows'], 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $out['total']]]);
    }

    public function store(AccountRequest $request): JsonResponse
    {
        return (new FinancialAccountResource($this->accounts->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(AccountRequest $request, FinancialAccount $account): FinancialAccountResource
    {
        return new FinancialAccountResource($this->accounts->update($account, $request->validated()));
    }

    public function destroy(FinancialAccount $account): JsonResponse
    {
        $this->accounts->delete($account);

        return response()->json(null, 204);
    }

    public function adjust(AccountAdjustRequest $request, FinancialAccount $account): FinancialAccountResource
    {
        $v = $request->validated();
        $this->accounts->adjust($account, $v['direction'], (string) $v['amount'], $v['reason'], $v['date'] ?? null);

        return new FinancialAccountResource($account->refresh());
    }
}
