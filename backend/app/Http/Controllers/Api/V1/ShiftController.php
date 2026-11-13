<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ShiftCashRequest;
use App\Http\Requests\Sales\ShiftCloseRequest;
use App\Http\Requests\Sales\ShiftOpenRequest;
use App\Http\Resources\ShiftResource;
use App\Models\CashShift;
use App\Services\PermissionService;
use App\Services\Sales\InvoicePdfService;
use App\Services\Sales\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** FR-716 */
class ShiftController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly ShiftService $shifts, private readonly PermissionService $permissions, private readonly InvoicePdfService $pdf) {}

    public function current(Request $request): JsonResponse
    {
        $shift = $this->shifts->current($request->user());

        return response()->json(['data' => $shift ? new ShiftResource($shift) : null]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CashShift::query()->with('cashier')->orderByDesc('id');
        if (! $this->permissions->userHas($request->user(), 'shifts.view_all')) {
            $query->where('cashier_id', $request->user()->id);
        }
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        return $this->paged($query, $request, ShiftResource::class);
    }

    public function show(Request $request, CashShift $shift): ShiftResource
    {
        $this->assertVisible($request, $shift);

        return new ShiftResource($shift->load('cashier'));
    }

    public function open(ShiftOpenRequest $request): JsonResponse
    {
        $d = $request->validated();
        $shift = $this->shifts->open($request->user(), (int) $d['cash_account_id'], (string) ($d['opening_float'] ?? '0'));

        return (new ShiftResource($shift))->response()->setStatusCode(201);
    }

    public function cash(ShiftCashRequest $request, CashShift $shift): ShiftResource
    {
        $this->assertOwnsOrCloseAny($request, $shift);
        $d = $request->validated();
        $this->shifts->cashMovement($shift, $d['direction'], (string) $d['amount'], $d['reason']);

        return new ShiftResource($shift->refresh());
    }

    public function close(ShiftCloseRequest $request, CashShift $shift): ShiftResource
    {
        $this->assertOwnsOrCloseAny($request, $shift);
        $d = $request->validated();
        $closed = $this->shifts->close($shift, $d['denomination_counts'], $d['note'] ?? null);
        try {
            $path = 'z-reports/'.now('Asia/Dhaka')->format('Y/m').'/'.$closed->shift_no.'.pdf';
            Storage::disk('local')->put($path, $this->pdf->zReport($closed));
            $closed->forceFill(['z_report_path' => $path])->save();
        } catch (ApiException $e) {
            // The shift is closed either way; the Z-report is regenerated on demand once the company profile is complete.
            report($e);
        }

        return new ShiftResource($closed->refresh()->load('cashier'));
    }

    public function zReport(Request $request, CashShift $shift): Response
    {
        $this->assertVisible($request, $shift);
        if ($shift->status !== 'closed') {
            throw new ApiException('INVALID_STATE', 409);
        }
        $bytes = $shift->z_report_path && Storage::disk('local')->exists($shift->z_report_path) ? Storage::disk('local')->get($shift->z_report_path) : $this->pdf->zReport($shift);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="Z-report_'.$shift->shift_no.'.pdf"']);
    }

    private function assertVisible(Request $request, CashShift $shift): void
    {
        if ($shift->cashier_id !== $request->user()->id && ! $this->permissions->userHas($request->user(), 'shifts.view_all')) {
            throw ApiException::forbidden();
        }
    }

    private function assertOwnsOrCloseAny(Request $request, CashShift $shift): void
    {
        if ($shift->cashier_id !== $request->user()->id && ! $this->permissions->userHas($request->user(), 'shifts.close_any')) {
            throw ApiException::forbidden();
        }
    }
}
