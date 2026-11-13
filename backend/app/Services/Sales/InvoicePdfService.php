<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\CashShift;
use App\Models\CompanyProfile;
use App\Models\FinancialAccount;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Pdf\PdfText;
use App\Services\Pdf\PdfFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;

/**
 * FR-901 to FR-906: every printed document is built on the server from what was stored, saved in the private folder
 * and served only through an authorised request. Nothing here reads the live company or customer record for a past sale.
 */
class InvoicePdfService
{
    /** The A4 invoice. Reuses the saved file unless asked to rebuild (a void must show its watermark). */
    public function ensure(Sale $sale, bool $force = false): string
    {
        if (! $force && $sale->pdf_path && Storage::disk('local')->exists($sale->pdf_path)) {
            return $sale->pdf_path;
        }
        $path = 'invoices/'.$sale->sale_date->setTimezone('Asia/Dhaka')->format('Y/m').'/'.$sale->invoice_no.'.pdf';
        Storage::disk('local')->put($path, $this->a4($sale));
        $sale->forceFill(['pdf_path' => $path, 'pdf_generated_at' => now()])->save();

        return $path;
    }

    public function a4(Sale $sale): string
    {
        $this->guardCompany($sale);
        $data = $this->saleData($sale);
        $mpdf = PdfFactory::make('Invoice '.$sale->invoice_no);
        $mpdf->SetHTMLFooter($this->footer($sale->company_snapshot, $sale->invoice_no));
        $this->watermark($mpdf, $sale);
        $mpdf->WriteHTML(View::make('pdf::invoice', $data)->render());

        return $mpdf->Output('', 'S');
    }

    /** 80 mm thermal receipt: one tall page sized to its content (FR-905). */
    public function receipt(Sale $sale): string
    {
        $this->guardCompany($sale);
        $data = $this->saleData($sale);
        $height = max(130, 105 + 11 * $data['items']->whereNull('bundle_parent_line')->count() + 6 * $data['payments']->count());
        $mpdf = PdfFactory::make('Receipt '.$sale->invoice_no, ['format' => [80, $height], 'margin' => [4, 4, 4, 4]]);
        $mpdf->WriteHTML(View::make('pdf::receipt', $data)->render());

        return $mpdf->Output('', 'S');
    }

    public function ensureCreditNote(SaleReturn $return): string
    {
        if ($return->pdf_path && Storage::disk('local')->exists($return->pdf_path)) {
            return $return->pdf_path;
        }
        $path = 'credit-notes/'.now('Asia/Dhaka')->format('Y/m').'/'.$return->return_no.'.pdf';
        Storage::disk('local')->put($path, $this->creditNote($return));
        $return->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    public function creditNote(SaleReturn $return): string
    {
        $sale = Sale::query()->findOrFail($return->sale_id);
        $lines = [];
        foreach ($return->items()->get() as $ri) {
            $item = DB::table('sale_items')->where('id', $ri->sale_item_id)->first(['product_name_snapshot', 'sku_snapshot']);
            $lines[] = ['name' => $item->product_name_snapshot, 'sku' => $item->sku_snapshot, 'condition' => $ri->condition, 'qty' => $ri->qty, 'tax' => $ri->tax_amount, 'total' => $ri->line_total];
        }
        $mpdf = PdfFactory::make('Credit note '.$return->return_no);
        $mpdf->SetHTMLFooter($this->footer($sale->company_snapshot, $return->return_no));
        $mpdf->WriteHTML(View::make('pdf::credit-note', ['return' => $return, 'sale' => $sale, 'lines' => $lines])->render());

        return $mpdf->Output('', 'S');
    }

    public function ensureQuotation(Quotation $q): string
    {
        $path = 'quotations/'.now('Asia/Dhaka')->format('Y/m').'/'.$q->quote_no.'.pdf';
        Storage::disk('local')->put($path, $this->quotation($q));
        $q->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    public function quotation(Quotation $q): string
    {
        $company = CompanyProfile::current();
        if (! $company->invoiceReady()) {
            throw new ApiException('COMPANY_PROFILE_INCOMPLETE', 422, '', [], ['missing' => $company->missingInvoiceFields()]);
        }
        $snap = SaleSnapshots::company($company);
        $q->loadMissing('items');
        $mpdf = PdfFactory::make('Quotation '.$q->quote_no);
        $mpdf->SetHTMLFooter($this->footer($snap, $q->quote_no));
        $mpdf->WriteHTML(View::make('pdf::quotation', ['quotation' => $q, 'company' => $snap])->render());

        return $mpdf->Output('', 'S');
    }

    public function zReport(CashShift $shift): string
    {
        $company = CompanyProfile::current();
        if (! $company->invoiceReady()) {
            throw new ApiException('COMPANY_PROFILE_INCOMPLETE', 422, '', [], ['missing' => $company->missingInvoiceFields()]);
        }
        $snap = SaleSnapshots::company($company);
        $mpdf = PdfFactory::make('Z-report '.$shift->shift_no);
        $mpdf->SetHTMLFooter($this->footer($snap, $shift->shift_no));
        $mpdf->WriteHTML(View::make('pdf::z-report', ['shift' => $shift, 'company' => $snap, 'cashierName' => DB::table('users')->where('id', $shift->cashier_id)->value('full_name')])->render());

        return $mpdf->Output('', 'S');
    }

    /** Invoice_<InvoiceNo>_<CustomerName>.pdf (FR-901). */
    public function downloadName(Sale $sale): string
    {
        return 'Invoice_'.$sale->invoice_no.'_'.PdfText::slug((string) ($sale->customer_snapshot['name'] ?? '')).'.pdf';
    }

    private function guardCompany(Sale $sale): void
    {
        $c = $sale->company_snapshot;
        if (empty($c['legal_name']) || empty($c['address_line1']) || empty($c['phones'])) {
            throw new ApiException('COMPANY_PROFILE_INCOMPLETE', 422);
        }
    }

    /** @return array<string, mixed> */
    private function saleData(Sale $sale): array
    {
        $payments = $sale->payments()->get();
        $names = FinancialAccount::withTrashed()->whereIn('id', $payments->pluck('account_id'))->pluck('name', 'id')->all();
        $users = DB::table('users')->whereIn('id', array_filter([$sale->cashier_id, $sale->salesperson_id]))->pluck('full_name', 'id');
        $signature = $sale->company_snapshot['signature_url'] ?? null;
        $sigFile = $signature ? public_path(ltrim((string) $signature, '/')) : null;

        return [
            'sale' => $sale, 'items' => $sale->items()->get(), 'payments' => $payments, 'accountNames' => $names,
            'verifyUrl' => rtrim((string) config('app.url'), '/').'/verify/'.$sale->verify_token,
            'branchName' => DB::table('branches')->where('id', $sale->branch_id)->value('name'),
            'cashierName' => $users[$sale->cashier_id] ?? '', 'salespersonName' => $users[$sale->salesperson_id] ?? '',
            'shiftNo' => $sale->shift_id ? DB::table('cash_shifts')->where('id', $sale->shift_id)->value('shift_no') : null,
            'signature' => $sigFile && is_file($sigFile) ? $sigFile : null,
        ];
    }

    /** @param  array<string, mixed>  $company */
    private function footer(array $company, string $docNo): string
    {
        return PdfFactory::footer($company, $docNo);
    }

    private function watermark(Mpdf $mpdf, Sale $sale): void
    {
        if ($sale->status === 'void') {
            $mpdf->SetWatermarkText('VOID', 0.12);
            $mpdf->showWatermarkText = true;
        } elseif ($sale->payment_status === 'paid') {
            $mpdf->SetWatermarkText('PAID', 0.05);
            $mpdf->showWatermarkText = true;
        }
    }
}
