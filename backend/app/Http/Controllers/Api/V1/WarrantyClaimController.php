<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\WarrantyClaimRequest;
use App\Http\Requests\Sales\WarrantyUpdateRequest;
use App\Http\Resources\WarrantyClaimResource;
use App\Models\WarrantyClaim;
use App\Services\Sales\WarrantyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** FR-717 */
class WarrantyClaimController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly WarrantyService $warranty) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = WarrantyClaim::query()->orderByDesc('id');
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.customer_id')) {
            $query->where('customer_id', (int) $request->input('filter.customer_id'));
        }
        if ($q = trim((string) $request->input('search', ''))) {
            $query->where('claim_no', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%');
        }
        $result = $this->paged($query, $request, WarrantyClaimResource::class);
        $this->decorate($result->resource);

        return $result;
    }

    public function store(WarrantyClaimRequest $request): JsonResponse
    {
        $claim = $this->warranty->create($request->user(), $request->validated());
        $this->decorate([$claim]);

        return (new WarrantyClaimResource($claim))->response()->setStatusCode(201);
    }

    public function update(WarrantyUpdateRequest $request, WarrantyClaim $warrantyClaim): WarrantyClaimResource
    {
        $claim = $this->warranty->update($request->user(), $warrantyClaim, $request->validated());
        $this->decorate([$claim]);

        return new WarrantyClaimResource($claim);
    }

    /** Names for the list, loaded once for the page. @param  iterable<WarrantyClaim>  $claims */
    private function decorate(iterable $claims): void
    {
        $claims = collect($claims);
        $items = DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->whereIn('i.id', $claims->pluck('sale_item_id'))->get(['i.id', 'i.product_name_snapshot', 's.invoice_no', 's.customer_snapshot'])->keyBy('id');
        foreach ($claims as $c) {
            $row = $items[$c->sale_item_id] ?? null;
            $c->product_name = $row?->product_name_snapshot;
            $c->invoice_no = $row?->invoice_no;
            $c->customer_name = $row ? (json_decode((string) $row->customer_snapshot, true)['name'] ?? null) : null;
        }
    }
}
