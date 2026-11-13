<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Http\Resources\StockDocumentResource;
use App\Models\StockTransfer;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** FR-405 */
class StockTransferController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockTransfer::query()->with(['fromWarehouse', 'toWarehouse'])->orderByDesc('id');
        foreach (['status', 'from_warehouse_id', 'to_warehouse_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }
        if ($q = trim((string) $request->input('search', ''))) {
            $query->where('transfer_no', 'like', '%'.$q.'%');
        }

        return $this->paged($query, $request, StockDocumentResource::class);
    }

    public function show(StockTransfer $stockTransfer): StockDocumentResource
    {
        return new StockDocumentResource($stockTransfer->load(['fromWarehouse', 'toWarehouse', 'items.variant']));
    }

    public function store(StockTransferRequest $request): JsonResponse
    {
        return (new StockDocumentResource($this->transfers->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(StockTransferRequest $request, StockTransfer $stockTransfer): StockDocumentResource
    {
        return new StockDocumentResource($this->transfers->update($stockTransfer, $request->validated()));
    }

    public function send(StockTransfer $stockTransfer): StockDocumentResource
    {
        return new StockDocumentResource($this->transfers->send($stockTransfer));
    }

    public function receive(Request $request, StockTransfer $stockTransfer): StockDocumentResource
    {
        $data = $request->validate(['items' => ['sometimes', 'array'], 'items.*.item_id' => ['required_without:items.*.variant_id', 'integer'], 'items.*.variant_id' => ['required_without:items.*.item_id', 'integer'], 'items.*.qty_received' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,3']]);

        return new StockDocumentResource($this->transfers->receive($stockTransfer, $data['items'] ?? null));
    }

    public function cancel(StockTransfer $stockTransfer): StockDocumentResource
    {
        return new StockDocumentResource($this->transfers->cancel($stockTransfer));
    }

    public function destroy(StockTransfer $stockTransfer): JsonResponse
    {
        $this->transfers->delete($stockTransfer);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
