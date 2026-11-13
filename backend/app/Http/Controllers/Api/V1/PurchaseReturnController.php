<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseReturnRequest;
use App\Http\Resources\PurchaseDocumentResource;
use App\Models\PurchaseReturn;
use App\Services\Purchasing\PurchaseReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** FR-504 */
class PurchaseReturnController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly PurchaseReturnService $returns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PurchaseReturn::query()->with(['supplier', 'warehouse'])->orderByDesc('return_date')->orderByDesc('id');
        if ($q = trim((string) $request->input('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('return_no', 'like', $like)->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like)));
        }
        foreach (['status', 'supplier_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }

        return $this->paged($query, $request, PurchaseDocumentResource::class);
    }

    public function show(PurchaseReturn $purchaseReturn): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($purchaseReturn->load(['supplier', 'warehouse', 'items.variant']));
    }

    public function store(PurchaseReturnRequest $request): JsonResponse
    {
        return (new PurchaseDocumentResource($this->returns->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(PurchaseReturnRequest $request, PurchaseReturn $purchaseReturn): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->returns->update($purchaseReturn, $request->validated()));
    }

    public function post(PurchaseReturn $purchaseReturn): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->returns->post($purchaseReturn));
    }

    public function cancel(PurchaseReturn $purchaseReturn): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->returns->cancel($purchaseReturn));
    }
}
