<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\QuotationRequest;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use App\Services\PermissionService;
use App\Services\Sales\InvoicePdfService;
use App\Services\Sales\QuotationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** FR-708 */
class QuotationController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly QuotationService $quotations, private readonly InvoicePdfService $pdf, private readonly PermissionService $permissions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Quotation::query()->orderByDesc('id');
        $this->scope($request, $query);
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($q = trim((string) $request->input('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('quote_no', 'like', $like)->orWhere('customer_snapshot', 'like', $like));
        }

        return $this->paged($query, $request, QuotationResource::class);
    }

    public function show(Request $request, Quotation $quotation): QuotationResource
    {
        $this->assertVisible($request, $quotation);

        return new QuotationResource($quotation->load('items'));
    }

    public function store(QuotationRequest $request): JsonResponse
    {
        return (new QuotationResource($this->quotations->create($request->user(), $request->validated())->load('items')))->response()->setStatusCode(201);
    }

    public function status(Request $request, Quotation $quotation): QuotationResource
    {
        $this->assertVisible($request, $quotation);
        $data = $request->validate(['status' => ['required', 'in:sent,accepted,cancelled']]);

        return new QuotationResource($this->quotations->setStatus($quotation, $data['status'])->load('items'));
    }

    public function convert(Request $request, Quotation $quotation): JsonResponse
    {
        $this->assertVisible($request, $quotation);
        $data = $request->validate(['warehouse_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->quotations->convert($quotation->load('items'), (int) $data['warehouse_id'])]);
    }

    public function pdf(Request $request, Quotation $quotation): Response
    {
        $this->assertVisible($request, $quotation);
        $bytes = $quotation->pdf_path && Storage::disk('local')->exists($quotation->pdf_path) ? Storage::disk('local')->get($quotation->pdf_path) : Storage::disk('local')->get($this->pdf->ensureQuotation($quotation));

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="Quotation_'.$quotation->quote_no.'.pdf"']);
    }

    /** @param  Builder<Quotation>  $query */
    private function scope(Request $request, $query): void
    {
        if (! $this->permissions->userHas($request->user(), 'sales.view_all')) {
            $query->where('created_by', $request->user()->id);
        }
    }

    private function assertVisible(Request $request, Quotation $q): void
    {
        if (! $this->permissions->userHas($request->user(), 'sales.view_all') && $q->created_by !== $request->user()->id) {
            throw ApiException::forbidden();
        }
    }
}
