<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SaleReturnRequest;
use App\Http\Resources\SaleReturnResource;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\Sales\InvoicePdfService;
use App\Services\Sales\SaleAccess;
use App\Services\Sales\SaleReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** FR-715 */
class SaleReturnController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly SaleReturnService $returns, private readonly SaleAccess $access, private readonly InvoicePdfService $pdf) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SaleReturn::query()->with('sale')->orderByDesc('id');
        if (! $this->access->canViewAll($request->user())) {
            $query->whereIn('sale_id', Sale::query()->where('cashier_id', $request->user()->id)->select('id'));
        }
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.sale_id')) {
            $query->where('sale_id', (int) $request->input('filter.sale_id'));
        }

        return $this->paged($query, $request, SaleReturnResource::class);
    }

    public function show(Request $request, SaleReturn $saleReturn): SaleReturnResource
    {
        $this->access->assertCanView($request->user(), Sale::query()->findOrFail($saleReturn->sale_id));

        return new SaleReturnResource($saleReturn->load(['items', 'sale']));
    }

    public function store(SaleReturnRequest $request): JsonResponse
    {
        $sale = Sale::query()->findOrFail((int) $request->input('sale_id'));
        $this->access->assertCanView($request->user(), $sale);
        $return = $this->returns->create($request->user(), $sale, $request->validated());

        return (new SaleReturnResource($return->load(['items', 'sale'])))->response()->setStatusCode(201);
    }

    public function approve(Request $request, SaleReturn $saleReturn): SaleReturnResource
    {
        return new SaleReturnResource($this->returns->post($request->user(), $saleReturn)->load(['items', 'sale']));
    }

    public function cancel(Request $request, SaleReturn $saleReturn): SaleReturnResource
    {
        return new SaleReturnResource($this->returns->cancel($request->user(), $saleReturn)->load(['items', 'sale']));
    }

    public function pdf(Request $request, SaleReturn $saleReturn): Response
    {
        $this->access->assertCanView($request->user(), Sale::query()->findOrFail($saleReturn->sale_id));
        if ($saleReturn->status !== 'posted') {
            throw new ApiException('INVALID_STATE', 409);
        }
        $path = $this->pdf->ensureCreditNote($saleReturn);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="CreditNote_'.$saleReturn->return_no.'.pdf"']);
    }
}
