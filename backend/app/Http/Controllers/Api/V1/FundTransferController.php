<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FundTransferRequest;
use App\Http\Requests\Finance\ReasonRequest;
use App\Http\Resources\FundTransferResource;
use App\Models\FundTransfer;
use App\Services\Finance\FundTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** FR-1004 */
class FundTransferController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly FundTransferService $transfers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FundTransfer::query()->with(['from', 'to'])->orderByDesc('transfer_date')->orderByDesc('id');
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.account_id')) {
            $id = (int) $request->input('filter.account_id');
            $query->where(fn ($w) => $w->where('from_account_id', $id)->orWhere('to_account_id', $id));
        }
        if ($request->filled('filter.from')) {
            $query->where('transfer_date', '>=', $request->input('filter.from'));
        }
        if ($request->filled('filter.to')) {
            $query->where('transfer_date', '<=', $request->input('filter.to'));
        }

        return $this->paged($query, $request, FundTransferResource::class);
    }

    public function show(FundTransfer $transfer): FundTransferResource
    {
        return new FundTransferResource($transfer->load(['from', 'to']));
    }

    public function store(FundTransferRequest $request): JsonResponse
    {
        return (new FundTransferResource($this->transfers->create($request->user(), $request->validated())->load(['from', 'to'])))->response()->setStatusCode(201);
    }

    public function void(ReasonRequest $request, FundTransfer $transfer): FundTransferResource
    {
        return new FundTransferResource($this->transfers->void($request->user(), $transfer, $request->validated('reason'))->load(['from', 'to']));
    }
}
