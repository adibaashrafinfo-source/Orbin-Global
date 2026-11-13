<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PartyAdjustmentRequest;
use App\Http\Requests\Finance\PartyWriteOffRequest;
use App\Services\Finance\FinancePdfService;
use App\Services\Finance\PartyAdjustmentService;
use App\Services\Finance\PartyLedgerService;
use App\Support\Dec;
use App\Support\PartyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** FR-1001, FR-1008 */
class PartyLedgerController extends Controller
{
    public function __construct(private readonly PartyLedgerService $ledger, private readonly PartyAdjustmentService $adjustments, private readonly FinancePdfService $pdf) {}

    public function show(Request $request): JsonResponse
    {
        $v = $this->query($request);
        $party = PartyDirectory::find($v['party_type'], (int) $v['party_id']) ?? throw ApiException::notFound();
        $data = $this->ledger->statement($v['party_type'], (int) $v['party_id'], $v['from'] ?? null, $v['to'] ?? null);

        return response()->json(['data' => [
            'party' => ['type' => $v['party_type'], 'id' => $party->id, 'code' => $party->code, 'name' => $party->name, 'balance' => Dec::fmt($this->ledger->balance($v['party_type'], (int) $party->id), Dec::MONEY)],
            'opening' => Dec::fmt($data['opening'], Dec::MONEY), 'closing' => Dec::fmt($data['closing'], Dec::MONEY),
            'total_debit' => Dec::fmt($data['total_debit'], Dec::MONEY), 'total_credit' => Dec::fmt($data['total_credit'], Dec::MONEY), 'rows' => $data['rows'],
        ]]);
    }

    public function statementPdf(Request $request): Response
    {
        $v = $this->query($request);
        $bytes = $this->pdf->statement($v['party_type'], (int) $v['party_id'], $v['from'] ?? null, $v['to'] ?? null);
        $code = PartyDirectory::find($v['party_type'], (int) $v['party_id'])?->code ?? 'party';

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="Statement_'.$code.'.pdf"']);
    }

    public function writeOff(PartyWriteOffRequest $request): JsonResponse
    {
        $v = $request->validated();
        $done = $this->adjustments->writeOff($v['party_type'], (int) $v['party_id'], (string) $v['amount'], $v['kind'], $v['reason'], $v['date'] ?? null);

        return response()->json(['data' => ['documents' => $done, 'balance' => Dec::fmt($this->ledger->balance($v['party_type'], (int) $v['party_id']), Dec::MONEY)]], 201);
    }

    public function adjust(PartyAdjustmentRequest $request): JsonResponse
    {
        $v = $request->validated();
        $this->adjustments->adjust($v['party_type'], (int) $v['party_id'], $v['direction'], (string) $v['amount'], $v['reason'], $v['date'] ?? null);

        return response()->json(['data' => ['balance' => Dec::fmt($this->ledger->balance($v['party_type'], (int) $v['party_id']), Dec::MONEY)]], 201);
    }

    /** @return array<string, mixed> */
    private function query(Request $request): array
    {
        return $request->validate([
            'party_type' => ['required', 'in:customer,supplier'], 'party_id' => ['required', 'integer'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
    }
}
