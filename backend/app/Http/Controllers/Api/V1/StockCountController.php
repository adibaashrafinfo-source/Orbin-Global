<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockCountRequest;
use App\Http\Resources\StockDocumentResource;
use App\Models\StockCount;
use App\Services\Inventory\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** FR-406 */
class StockCountController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly StockCountService $counts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockCount::query()->with('warehouse')->orderByDesc('id');
        foreach (['status', 'warehouse_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }

        return $this->paged($query, $request, StockDocumentResource::class);
    }

    public function show(StockCount $stockCount): StockDocumentResource
    {
        return new StockDocumentResource($stockCount->load(['warehouse', 'items.variant']));
    }

    public function store(StockCountRequest $request): JsonResponse
    {
        return (new StockDocumentResource($this->counts->create($request->validated())))->response()->setStatusCode(201);
    }

    public function start(StockCount $stockCount): StockDocumentResource
    {
        return new StockDocumentResource($this->counts->start($stockCount));
    }

    public function addItem(Request $request, StockCount $stockCount): StockDocumentResource
    {
        $data = $request->validate(['variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')]]);

        return new StockDocumentResource($this->counts->addItem($stockCount, (int) $data['variant_id']));
    }

    public function enter(Request $request, StockCount $stockCount): StockDocumentResource
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'], 'items.*.variant_id' => ['required', 'integer'],
            'items.*.counted_qty' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,3'], 'items.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return new StockDocumentResource($this->counts->enter($stockCount, $data['items']));
    }

    public function review(StockCount $stockCount): StockDocumentResource
    {
        return new StockDocumentResource($this->counts->review($stockCount));
    }

    public function post(StockCount $stockCount): StockDocumentResource
    {
        return new StockDocumentResource($this->counts->post($stockCount));
    }

    public function cancel(StockCount $stockCount): StockDocumentResource
    {
        return new StockDocumentResource($this->counts->cancel($stockCount));
    }

    public function destroy(StockCount $stockCount): JsonResponse
    {
        $this->counts->delete($stockCount);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
