<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseOrderRequest;
use App\Http\Resources\PurchaseDocumentResource;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderPdfService;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/** FR-502, FR-507 */
class PurchaseOrderController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly PurchaseOrderService $orders, private readonly PurchaseOrderPdfService $pdf) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PurchaseOrder::query()->with('supplier')->orderByDesc('order_date')->orderByDesc('id');
        if ($q = trim((string) $request->input('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('po_no', 'like', $like)->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like)));
        }
        foreach (['status', 'supplier_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }
        if ($request->filled('filter.from')) {
            $query->where('order_date', '>=', $request->input('filter.from'));
        }
        if ($request->filled('filter.to')) {
            $query->where('order_date', '<=', $request->input('filter.to'));
        }

        return $this->paged($query, $request, PurchaseDocumentResource::class);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($purchaseOrder->load(['supplier', 'items.variant']));
    }

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        return (new PurchaseDocumentResource($this->orders->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->orders->update($purchaseOrder, $request->validated()));
    }

    public function approve(PurchaseOrder $purchaseOrder): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->orders->approve($purchaseOrder));
    }

    public function cancel(PurchaseOrder $purchaseOrder): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->orders->cancel($purchaseOrder));
    }

    public function close(PurchaseOrder $purchaseOrder): PurchaseDocumentResource
    {
        return new PurchaseDocumentResource($this->orders->close($purchaseOrder));
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->orders->delete($purchaseOrder);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function pdf(PurchaseOrder $purchaseOrder): Response
    {
        return $this->pdfResponse($purchaseOrder);
    }

    /** A link anyone holding it can open for seven days, for sending the order to the supplier by email or WhatsApp. */
    public function shareLink(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->pdf->render($purchaseOrder); // refuses early (company profile incomplete) instead of handing out a dead link
        $expires = now()->addDays(7);

        return response()->json(['data' => ['url' => URL::temporarySignedRoute('shared.purchase-orders.pdf', $expires, ['purchaseOrder' => $purchaseOrder->id]), 'expires_at' => $expires->toIso8601String()]]);
    }

    public function sharedPdf(PurchaseOrder $purchaseOrder): Response
    {
        return $this->pdfResponse($purchaseOrder);
    }

    /** Last five purchase costs of a variant (FR-507). */
    public function priceHistory(Request $request, ProductVariant $variant): JsonResponse
    {
        $supplier = $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null;

        return response()->json(['data' => $this->orders->priceHistory($variant->id, $supplier)]);
    }

    private function pdfResponse(PurchaseOrder $po): Response
    {
        return response($this->pdf->render($po), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$po->po_no.'.pdf"']);
    }
}
