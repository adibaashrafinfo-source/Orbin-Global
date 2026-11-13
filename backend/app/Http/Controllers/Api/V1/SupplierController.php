<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\Purchasing\SupplierService;
use App\Support\Dec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** FR-501 */
class SupplierController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Supplier::query()->orderBy('name');
        if ($q = trim((string) $request->input('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('contact_person', 'like', $like)->orWhere('phones', 'like', $like));
        }
        if ($request->filled('filter.supplier_type')) {
            $query->where('supplier_type', $request->input('filter.supplier_type'));
        }
        if ($request->has('filter.is_active') && $request->input('filter.is_active') !== '') {
            $query->where('is_active', filter_var($request->input('filter.is_active'), FILTER_VALIDATE_BOOL));
        }
        $result = $this->paged($query, $request, SupplierResource::class);
        // Balances for the page in one query instead of one per row.
        $ids = collect($result->resource)->pluck('id')->all();
        $balances = DB::table('party_ledger_entries')->where('party_type', 'supplier')->whereIn('party_id', $ids)->groupBy('party_id')
            ->selectRaw('party_id, COALESCE(SUM(credit), 0) as c, COALESCE(SUM(debit), 0) as d')->get()->keyBy('party_id');
        foreach ($result->resource as $s) {
            $b = $balances[$s->id] ?? null;
            $s->balance = Dec::fmt($b ? Dec::parse((string) $b->c, Dec::MONEY) - Dec::parse((string) $b->d, Dec::MONEY) : 0, Dec::MONEY);
        }

        return $result;
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        return (new SupplierResource($this->suppliers->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        return new SupplierResource($this->suppliers->update($supplier, $request->validated()));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->suppliers->delete($supplier);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
