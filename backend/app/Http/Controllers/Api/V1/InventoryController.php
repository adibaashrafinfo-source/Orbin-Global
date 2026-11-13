<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\Inventory\ReorderService;
use App\Services\Inventory\StockQueryService;
use App\Services\PermissionService;
use App\Support\CsvDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** FR-401, FR-402, FR-404, FR-407 to FR-411: reading stock. Cost and value are only returned to users with products.view_cost. */
class InventoryController extends Controller
{
    public function __construct(
        private readonly StockQueryService $query,
        private readonly ReorderService $reorder,
        private readonly PermissionService $permissions,
    ) {}

    private function canCost(Request $request): bool
    {
        return $this->permissions->userHas($request->user(), 'products.view_cost');
    }

    public function overview(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'status' => ['sometimes', 'nullable', Rule::in(['', 'out', 'low', 'over', 'ok', 'dead', 'in'])]]);
        $canCost = $this->canCost($request);
        $page = $this->query->overview($request->only(['warehouse_id', 'category_id', 'search', 'status', 'active']), $canCost)->paginate((int) $request->input('per_page', 25));
        $warehouse = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'kpis' => $this->query->kpis($warehouse, $canCost)]]);
    }

    public function movements(Request $request): JsonResponse|BinaryFileResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'type' => ['sometimes', 'array'], 'type.*' => ['string']]);
        $canCost = $this->canCost($request);
        $q = $this->query->movements($request->only(['variant_id', 'warehouse_id', 'type', 'ref_no', 'from', 'to']), $canCost);
        if ($request->input('format') === 'csv') {
            $headers = ['Date', 'Type', 'SKU', 'Warehouse', 'Qty', 'Balance', ...($canCost ? ['Unit cost'] : []), 'Reference', 'Reason', 'User'];
            $rows = (function () use ($q, $canCost) {
                foreach ($q->limit(50000)->cursor() as $m) {
                    yield [$m->occurred_at, $m->movement_type, $m->sku, $m->warehouse, $m->qty_delta, $m->balance_after, ...($canCost ? [$m->unit_cost] : []), $m->ref_no, $m->reason, $m->user];
                }
            })();

            return CsvDownload::make('stock-movements.csv', $headers, $rows);
        }
        $page = $q->paginate((int) $request->input('per_page', 25));

        return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function card(Request $request, ProductVariant $variant): JsonResponse|BinaryFileResponse
    {
        $request->validate(['warehouse_id' => ['sometimes', 'nullable', 'integer'], 'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $canCost = $this->canCost($request);
        $card = $this->query->card($variant->id, $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null, $request->input('from'), $request->input('to'), $canCost);
        if ($request->input('format') === 'csv') {
            $rows = array_map(fn ($r) => [$r['occurred_at'], $r['movement_type'], $r['warehouse'], $r['qty_delta'], $r['running'], ...($canCost ? [$r['unit_cost']] : []), $r['ref_no'], $r['reason']], $card['rows']);

            return CsvDownload::make("stock-card-{$variant->sku}.csv", ['Date', 'Type', 'Warehouse', 'Qty', 'Balance', ...($canCost ? ['Unit cost'] : []), 'Reference', 'Reason'], $rows);
        }

        return response()->json(['data' => $card + ['variant' => ['id' => $variant->id, 'sku' => $variant->sku, 'variant_name' => $variant->variant_name]]]);
    }

    /** Stock value as of a day. Value is cost, so the cost permission is required, not just inventory.reports. */
    public function valuation(Request $request): JsonResponse|BinaryFileResponse
    {
        if (! $this->canCost($request)) {
            throw ApiException::forbidden();
        }
        $data = $request->validate(['as_of' => ['sometimes', 'date_format:Y-m-d'], 'warehouse_id' => ['sometimes', 'nullable', 'integer'], 'category_id' => ['sometimes', 'nullable', 'integer']]);
        $result = $this->query->valuation($data['as_of'] ?? now('Asia/Dhaka')->toDateString(), $data['warehouse_id'] ?? null, $data['category_id'] ?? null);
        if ($request->input('format') === 'csv') {
            return CsvDownload::make("stock-valuation-{$result['as_of']}.csv", ['SKU', 'Variant', 'Product', 'Qty', 'Average cost', 'Value'], array_map(fn ($r) => [$r['sku'], $r['variant_name'], $r['product_name'], $r['qty'], $r['avg_cost'], $r['value']], $result['rows']));
        }

        return response()->json(['data' => $result]);
    }

    public function aging(Request $request): JsonResponse|BinaryFileResponse
    {
        $result = $this->query->aging($request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null, $this->canCost($request));
        if ($request->input('format') === 'csv') {
            $names = array_keys(StockQueryService::AGING_BUCKETS);

            return CsvDownload::make('stock-aging.csv', ['SKU', 'Variant', ...$names], array_map(fn ($r) => [$r['sku'], $r['variant_name'], ...array_map(fn ($b) => $r['buckets'][$b], $names)], $result['rows']));
        }

        return response()->json(['data' => $result]);
    }

    public function breakage(Request $request): JsonResponse
    {
        $rows = $this->query->breakage($request->only(['from', 'to', 'variant_id', 'user_id', 'supplier_id']));
        $canCost = $this->canCost($request);
        $total = 0;
        foreach ($rows as &$r) {
            $total += (float) $r['cost_impact'];
            if (! $canCost) {
                unset($r['unit_cost'], $r['cost_impact']);
            }
        }
        unset($r);

        return response()->json(['data' => $rows, 'meta' => ['count' => count($rows)] + ($canCost ? ['cost_impact' => number_format($total, 2, '.', '')] : [])]);
    }

    public function reorder(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reorder->suggestions($request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null, $this->canCost($request))]);
    }

    public function createPurchaseOrders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,3'],
            'lines.*.supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
        ]);
        $orders = $this->reorder->createDrafts($data['lines']);

        return response()->json(['data' => collect($orders)->map(fn ($o) => ['id' => $o->id, 'po_no' => $o->po_no, 'supplier_id' => $o->supplier_id, 'grand_total' => $o->grand_total])->all()], 201);
    }
}
