<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Http\Requests\Sales\HoldRequest;
use App\Http\Requests\Sales\VoidRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\AuditService;
use App\Services\Sales\InvoicePdfService;
use App\Services\Sales\SaleAccess;
use App\Services\Sales\SaleService;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** FR-711 to FR-714, FR-904 */
class SaleController extends Controller
{
    use PagedResponses;

    public function __construct(
        private readonly SaleService $sales,
        private readonly SaleAccess $access,
        private readonly InvoicePdfService $pdf,
        private readonly AuditService $audit,
    ) {}

    /** POST /sales/checkout. The Idempotency-Key header makes a double click return the first invoice, not a second one. */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['idempotency_key'] = (string) $request->header('Idempotency-Key', '');
        if ($data['idempotency_key'] === '') {
            throw new ApiException('VALIDATION', 422, '', ['idempotency_key' => ['errors.REQUIRED']]);
        }
        $sale = $this->sales->complete($request->user(), $data);

        return response()->json(['data' => $this->payload($sale, $request)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Sale::query()->with(['cashier'])->whereNotIn('status', ['draft', 'held'])->orderByDesc('sale_date')->orderByDesc('id');
        $this->access->scope($user, $query);
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.customer_id')) {
            $query->where('customer_id', (int) $request->input('filter.customer_id'));
        }
        if ($request->filled('filter.cashier_id') && $this->access->canViewAll($user)) {
            $query->where('cashier_id', (int) $request->input('filter.cashier_id'));
        }
        if ($request->filled('filter.payment_status')) {
            $query->where('payment_status', $request->input('filter.payment_status'));
        }
        if ($request->boolean('filter.due_only')) {
            $query->where('due_total', '>', 0);
        }
        if ($request->filled('filter.method')) {
            $query->whereIn('id', SalePayment::query()->where('method', $request->input('filter.method'))->select('sale_id'));
        }
        if ($request->filled('filter.from')) {
            $query->where('sale_date', '>=', CarbonImmutable::parse((string) $request->input('filter.from'), 'Asia/Dhaka')->startOfDay()->utc());
        }
        if ($request->filled('filter.to')) {
            $query->where('sale_date', '<=', CarbonImmutable::parse((string) $request->input('filter.to'), 'Asia/Dhaka')->endOfDay()->utc());
        }
        if ($q = trim((string) $request->input('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('invoice_no', 'like', $like)->orWhere('customer_snapshot', 'like', $like));
        }
        $totals = (clone $query)->reorder()->toBase()->where('status', '<>', 'void')->selectRaw('COUNT(*) as n, COALESCE(SUM(grand_total),0) as g, COALESCE(SUM(paid_total),0) as p, COALESCE(SUM(due_total),0) as d')->first();
        $result = $this->paged($query, $request, SaleResource::class);

        return $result->additional(['summary' => [
            'count' => (int) $totals->n, 'grand_total' => Dec::fmt(Dec::parse((string) $totals->g, Dec::MONEY), Dec::MONEY),
            'paid_total' => Dec::fmt(Dec::parse((string) $totals->p, Dec::MONEY), Dec::MONEY), 'due_total' => Dec::fmt(Dec::parse((string) $totals->d, Dec::MONEY), Dec::MONEY),
        ]])->response();
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        $this->access->assertCanView($request->user(), $sale);

        return response()->json(['data' => $this->payload($sale, $request, true)]);
    }

    /** A4 invoice. `reprint=1` counts a reprint on the invoice and in the audit log (FR-904); `inline=1` shows it instead of saving it. */
    public function pdf(Request $request, Sale $sale): Response
    {
        $this->access->assertCanView($request->user(), $sale);
        $this->ensureCompleted($sale);
        if ($request->boolean('reprint')) {
            DB::transaction(function () use ($sale): void {
                $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill(['reprint_count' => $locked->reprint_count + 1])->save();
                $this->audit->log('sale_reprint', 'sales', $locked->id, ['reprint_count' => $locked->reprint_count - 1], ['reprint_count' => $locked->reprint_count]);
            });
        }
        $path = $this->pdf->ensure($sale);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$this->pdf->downloadName($sale).'"',
        ]);
    }

    public function receipt(Request $request, Sale $sale): Response
    {
        $this->access->assertCanView($request->user(), $sale);
        $this->ensureCompleted($sale);
        $name = 'Receipt_'.$sale->invoice_no.'.pdf';

        return response($this->pdf->receipt($sale), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"']);
    }

    public function void(VoidRequest $request, Sale $sale): JsonResponse
    {
        $done = $this->sales->void($request->user(), $sale, (string) $request->validated('reason'));

        return response()->json(['data' => $this->payload($done, $request, true)]);
    }

    public function hold(HoldRequest $request): JsonResponse
    {
        $held = $this->sales->hold($request->user(), $request->validated());

        return response()->json(['data' => ['id' => $held->id, 'held_name' => $held->held_name]], 201);
    }

    /** The cashier's own parked bills (a manager sees everyone's). */
    public function held(Request $request): JsonResponse
    {
        $query = Sale::query()->where('status', 'held')->orderByDesc('id');
        $this->access->scope($request->user(), $query);
        $rows = $query->get(['id', 'held_name', 'customer_id', 'customer_snapshot', 'created_at', 'cashier_id', 'held_payload'])->map(fn ($s) => [
            'id' => $s->id, 'held_name' => $s->held_name, 'customer' => $s->customer_snapshot['name'] ?? null, 'held_at' => $s->created_at?->toIso8601String(),
            'line_count' => count($s->held_payload['lines'] ?? []),
        ]);

        return response()->json(['data' => $rows]);
    }

    public function resume(Request $request, Sale $sale): JsonResponse
    {
        $this->access->assertCanView($request->user(), $sale);

        return response()->json(['data' => $this->sales->resume($sale)]);
    }

    public function discardHeld(Request $request, Sale $sale): JsonResponse
    {
        $this->access->assertCanView($request->user(), $sale);
        $this->sales->resume($sale);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** FR-806: an accountant or manager confirms a mobile-money or bank payment they have checked. */
    public function confirmPayment(Request $request, SalePayment $payment): JsonResponse
    {
        if ($payment->verification !== 'pending') {
            throw new ApiException('INVALID_STATE', 409);
        }
        $payment->forceFill(['verification' => 'confirmed', 'verified_by' => $request->user()->id, 'verified_at' => now()])->save();
        $this->audit->log('payment_confirm', 'sale_payments', $payment->id, ['verification' => 'pending'], ['verification' => 'confirmed']);

        return response()->json(['data' => ['id' => $payment->id, 'verification' => 'confirmed']]);
    }

    private function ensureCompleted(Sale $sale): void
    {
        if (in_array($sale->status, ['draft', 'held'], true)) {
            throw new ApiException('INVALID_STATE', 409);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Sale $sale, Request $request, bool $full = false): array
    {
        $sale->load(['items', 'payments', 'cashier', 'salesperson']);
        $data = (new SaleResource($sale))->toArray($request);
        $data['pdf_url'] = '/api/v1/sales/'.$sale->id.'/pdf';
        $data['receipt_url'] = '/api/v1/sales/'.$sale->id.'/receipt';
        $data['download_name'] = $this->pdf->downloadName($sale);
        if ($full) {
            $data['returns'] = DB::table('sale_returns')->where('sale_id', $sale->id)->whereNull('deleted_at')->orderBy('id')->get(['id', 'return_no', 'status', 'refund_total', 'ledger_credit_total', 'return_date']);
            $data['timeline'] = DB::table('audit_logs')->where('entity_table', 'sales')->where('entity_id', $sale->id)->orderBy('id')->get(['occurred_at', 'action', 'reason']);
        }

        return $data;
    }
}
