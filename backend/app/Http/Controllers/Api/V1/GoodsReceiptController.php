<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\GoodsReceiptRequest;
use App\Http\Resources\PurchaseDocumentResource;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\GoodsReceiptService;
use App\Support\Dec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** FR-503 */
class GoodsReceiptController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = GoodsReceipt::query()->with(['supplier', 'warehouse'])->orderByDesc('received_date')->orderByDesc('id');
        if ($q = trim((string) $request->input('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('grn_no', 'like', $like)->orWhere('supplier_invoice_no', 'like', $like)->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like)));
        }
        foreach (['status', 'supplier_id', 'warehouse_id', 'po_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }
        if ($request->filled('filter.from')) {
            $query->where('received_date', '>=', $request->input('filter.from'));
        }
        if ($request->filled('filter.to')) {
            $query->where('received_date', '<=', $request->input('filter.to'));
        }

        return $this->paged($query, $request, PurchaseDocumentResource::class);
    }

    public function show(GoodsReceipt $goodsReceipt): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($goodsReceipt->load(['supplier', 'warehouse', 'items.variant', 'extraCosts']));
    }

    public function store(GoodsReceiptRequest $request): JsonResponse
    {
        return (new PurchaseDocumentResource($this->receipts->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(GoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->receipts->update($goodsReceipt, $request->validated()));
    }

    /** The live landed-cost panel: same arithmetic as posting, nothing is saved. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['allocation_basis' => ['sometimes', Rule::in(['value', 'quantity'])], ...GoodsReceiptRequest::lineRules('required')]);

        return response()->json(['data' => $this->receipts->preview($data['items'] ?? [], $data['extra_costs'] ?? [], $data['allocation_basis'] ?? 'value')]);
    }

    public function post(GoodsReceipt $goodsReceipt): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->receipts->post($goodsReceipt));
    }

    public function cancel(GoodsReceipt $goodsReceipt): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->receipts->cancel($goodsReceipt));
    }

    public function destroy(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $this->receipts->delete($goodsReceipt);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Lines still to receive on an order, as a starting point for a receipt against it. */
    public function fromOrder(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $lines = $purchaseOrder->items()->with('variant')->get()->map(function ($i) {
            $left = max(Dec::parse((string) $i->qty_ordered, Dec::QTY) - Dec::parse((string) $i->qty_received, Dec::QTY), 0);

            return [
                'po_item_id' => $i->id, 'variant_id' => $i->variant_id, 'sku' => $i->variant->sku, 'variant_name' => $i->variant->variant_name,
                'qty_ordered' => $i->qty_ordered, 'qty_received_so_far' => $i->qty_received,
                'qty_received' => Dec::fmt($left, Dec::QTY), 'qty_damaged' => '0.000', 'unit_cost' => $i->unit_cost,
            ];
        })->filter(fn ($l) => $l['qty_received'] !== '0.000')->values();

        return response()->json(['data' => ['po_id' => $purchaseOrder->id, 'supplier_id' => $purchaseOrder->supplier_id, 'status' => $purchaseOrder->status, 'items' => $lines]]);
    }
}
