<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PartyPaymentRequest;
use App\Http\Requests\Finance\ReasonRequest;
use App\Http\Resources\PartyPaymentResource;
use App\Models\PartyPayment;
use App\Models\User;
use App\Services\Finance\FinancePdfService;
use App\Services\Finance\PartyPaymentService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** FR-1002 */
class PartyPaymentController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly PartyPaymentService $payments, private readonly FinancePdfService $pdf, private readonly PermissionService $permissions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PartyPayment::query()->with('account')->orderByDesc('payment_date')->orderByDesc('id');
        foreach (['party_type', 'party_id', 'status', 'method', 'verification'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }
        if ($request->filled('filter.from')) {
            $query->where('payment_date', '>=', $request->input('filter.from'));
        }
        if ($request->filled('filter.to')) {
            $query->where('payment_date', '<=', $request->input('filter.to'));
        }
        if ($q = trim((string) $request->input('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('payment_no', 'like', $like)->orWhere('trx_id', 'like', $like)->orWhere('bank_ref', 'like', $like));
        }

        return $this->paged($query, $request, PartyPaymentResource::class);
    }

    public function show(Request $request, PartyPayment $payment): PartyPaymentResource
    {
        $this->assertCanSee($request->user(), $payment);

        return new PartyPaymentResource($payment->load(['account', 'allocations']));
    }

    /** What the party owes and on which documents, so the screen can offer the split before anything is posted. */
    public function openDocuments(Request $request): JsonResponse
    {
        $v = $request->validate(['party_type' => ['required', 'in:customer,supplier'], 'party_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->payments->openDocuments($request->user(), $v['party_type'], (int) $v['party_id'])]);
    }

    public function store(PartyPaymentRequest $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        $payment = $this->payments->record($request->user(), $request->validated(), $key !== '' ? mb_substr($key, 0, 80) : null);

        return (new PartyPaymentResource($payment->load(['account', 'allocations'])))->response()->setStatusCode(201);
    }

    public function void(ReasonRequest $request, PartyPayment $payment): PartyPaymentResource
    {
        return new PartyPaymentResource($this->payments->void($request->user(), $payment, $request->validated('reason'))->load(['account', 'allocations']));
    }

    public function confirm(Request $request, PartyPayment $payment): PartyPaymentResource
    {
        return new PartyPaymentResource($this->payments->confirm($payment)->load(['account', 'allocations']));
    }

    public function receipt(Request $request, PartyPayment $payment): Response
    {
        $this->assertCanSee($request->user(), $payment);
        $path = $this->pdf->ensureReceipt($payment);

        return Storage::disk('local')->response($path, $payment->payment_no.'.pdf', [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$payment->payment_no.'.pdf"',
        ]);
    }

    /** Anyone with ledger.view sees every payment; whoever took one (a cashier) sees their own. */
    private function assertCanSee(User $user, PartyPayment $payment): void
    {
        if (! $this->permissions->userHas($user, 'ledger.view') && $payment->created_by !== $user->id) {
            throw ApiException::forbidden();
        }
    }
}
