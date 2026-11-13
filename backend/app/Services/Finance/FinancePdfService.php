<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\CompanyProfile;
use App\Models\PartyPayment;
use App\Services\Pdf\PdfFactory;
use App\Services\Sales\SaleSnapshots;
use App\Support\Branches;
use App\Support\Dec;
use App\Support\PartyDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/** FR-905: the payment receipt (or voucher) and the customer and supplier statements, branded like the invoice. */
class FinancePdfService
{
    /** Rows written to the PDF at a time. */
    private const STATEMENT_SLICE = 200;

    public function __construct(private readonly PartyLedgerService $ledger) {}

    /** Save the receipt once and keep returning it; a void payment is re-rendered with its VOID mark. */
    public function ensureReceipt(PartyPayment $payment, bool $force = false): string
    {
        if (! $force && $payment->receipt_pdf_path && Storage::disk('local')->exists($payment->receipt_pdf_path)) {
            return $payment->receipt_pdf_path;
        }
        $path = 'receipts/'.now('Asia/Dhaka')->format('Y/m').'/'.$payment->payment_no.'.pdf';
        Storage::disk('local')->put($path, $this->receipt($payment));
        $payment->forceFill(['receipt_pdf_path' => $path])->save();

        return $path;
    }

    public function receipt(PartyPayment $payment): string
    {
        $snap = $this->company();
        $party = $this->party($payment->party_type, (int) $payment->party_id);
        $lastEntry = (int) DB::table('party_ledger_entries')->where(['ref_table' => 'party_payments', 'ref_id' => $payment->id])->max('id');
        $sign = $payment->party_type === 'customer' ? 1 : -1;
        $row = DB::table('party_ledger_entries')->where(['party_type' => $payment->party_type, 'party_id' => $payment->party_id])->where('id', '<=', $lastEntry)->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
        $balanceAfter = $sign * (Dec::parse((string) $row->d, Dec::MONEY) - Dec::parse((string) $row->c, Dec::MONEY));
        $allocations = [];
        foreach ($payment->allocations()->orderBy('id')->get() as $a) {
            $allocations[] = ['no' => match ($a->doc_table) {
                'sales' => DB::table('sales')->where('id', $a->doc_id)->value('invoice_no'),
                'goods_receipts' => DB::table('goods_receipts')->where('id', $a->doc_id)->value('grn_no'),
                default => 'Opening balance',
            }, 'amount' => (string) $a->amount];
        }
        $mpdf = PdfFactory::make('Payment '.$payment->payment_no);
        $mpdf->SetHTMLFooter(PdfFactory::footer($snap, $payment->payment_no));
        if ($payment->status === 'void') {
            $mpdf->SetWatermarkText('VOID', 0.12);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML(View::make('pdf::payment-receipt', [
            'payment' => $payment, 'company' => $snap, 'party' => $party, 'allocations' => $allocations, 'balanceAfter' => Dec::fmt($balanceAfter, Dec::MONEY),
            'accountName' => DB::table('financial_accounts')->where('id', $payment->account_id)->value('name'),
        ])->render());

        return $mpdf->Output('', 'S');
    }

    public function statement(string $type, int $partyId, ?string $from, ?string $to): string
    {
        $snap = $this->company();
        $party = $this->party($type, $partyId);
        $data = $this->ledger->statement($type, $partyId, $from, $to);
        $mpdf = PdfFactory::make(ucfirst($type).' statement '.($party['code'] ?? ''));
        $mpdf->SetHTMLFooter(PdfFactory::footer($snap, ($party['code'] ?? '').' · '.Branches::today()));
        // Written in slices of rows so a long history never has to be one huge HTML string and one huge table in memory.
        $chunks = array_chunk($data['rows'], self::STATEMENT_SLICE) ?: [[]];
        $last = count($chunks) - 1;
        foreach ($chunks as $i => $rows) {
            $mpdf->WriteHTML(View::make('pdf::statement', [
                'company' => $snap, 'party' => $party, 'type' => $type, 'from' => $from, 'to' => $to, 'rows' => $rows,
                'first' => $i === 0, 'last' => $i === $last,
                'opening' => Dec::fmt($data['opening'], Dec::MONEY), 'closing' => Dec::fmt($data['closing'], Dec::MONEY),
                'totalDebit' => Dec::fmt($data['total_debit'], Dec::MONEY), 'totalCredit' => Dec::fmt($data['total_credit'], Dec::MONEY),
            ])->render());
            unset($chunks[$i]);
        }

        return $mpdf->Output('', 'S');
    }

    /** @return array<string, mixed> */
    private function company(): array
    {
        $company = CompanyProfile::current();
        if (! $company->invoiceReady()) {
            throw new ApiException('COMPANY_PROFILE_INCOMPLETE', 422, '', [], ['missing' => $company->missingInvoiceFields()]);
        }

        return SaleSnapshots::company($company);
    }

    /** @return array{code: string, name: string, phones: array<int, string>, address: string} */
    private function party(string $type, int $id): array
    {
        PartyDirectory::find($type, $id) ?? throw ApiException::notFound();
        if ($type === 'customer') {
            $c = DB::table('customers')->where('id', $id)->first();

            return ['code' => $c->code, 'name' => $c->name.($c->company_name ? ' · '.$c->company_name : ''), 'phones' => (array) json_decode((string) $c->phones, true),
                'address' => trim(implode(', ', array_filter([$c->address_line1, $c->address_line2, $c->area, $c->district])))];
        }
        $s = DB::table('suppliers')->where('id', $id)->first();

        return ['code' => $s->code, 'name' => $s->name, 'phones' => (array) json_decode((string) ($s->phones ?? '[]'), true), 'address' => (string) ($s->address ?? '')];
    }
}
