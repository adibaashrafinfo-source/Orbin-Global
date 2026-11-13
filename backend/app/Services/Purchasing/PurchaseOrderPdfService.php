<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\CompanyProfile;
use App\Models\PurchaseOrder;
use App\Support\Money;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/** FR-502: the purchase order as a PDF with the same branding as the invoice. Blocked until the company profile is complete. */
class PurchaseOrderPdfService
{
    public function render(PurchaseOrder $po): string
    {
        $company = CompanyProfile::current();
        if (! $company->invoiceReady()) {
            throw new ApiException('COMPANY_PROFILE_INCOMPLETE', 422, '', [], ['missing' => $company->missingInvoiceFields()]);
        }
        $po->loadMissing(['supplier', 'items.variant']);
        $tmp = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($tmp);
        $mpdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => $tmp, 'default_font' => 'dejavusans', 'default_font_size' => 9, 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 16]);
        $mpdf->SetTitle('Purchase order '.$po->po_no);
        $mpdf->SetCreator('Orbin POS');
        $mpdf->SetCompression(config('orbin.pdf_compress', true));
        $mpdf->SetHTMLFooter('<div style="text-align:center;font-size:8pt;color:#002756">'.e($company->tagline ?: 'Trust. Growth. Excellence.').'</div>');
        $mpdf->WriteHTML($this->html($po, $company));

        return $mpdf->Output('', 'S');
    }

    private function html(PurchaseOrder $po, CompanyProfile $c): string
    {
        $logoFile = public_path('assets/brand/orbin-logo.jpg');
        $logo = is_file($logoFile) ? '<img src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoFile)).'" style="width:48mm" />' : '';
        $money = fn ($v) => 'Tk '.Money::of((string) $v)->format(false);
        $addr = e(trim($c->address_line1.' '.$c->address_line2.' '.$c->city));
        $phones = e(implode(', ', array_filter($c->phones ?? [])));
        $s = $po->supplier;
        $rows = '';
        foreach ($po->items as $n => $i) {
            $rows .= '<tr><td>'.($n + 1).'</td><td>'.e($i->variant->sku).'<br/><span style="color:#555">'.e((string) $i->variant->variant_name).'</span></td>'
                .'<td align="right">'.rtrim(rtrim((string) $i->qty_ordered, '0'), '.').'</td><td align="right">'.$money($i->unit_cost).'</td>'
                .'<td align="right">'.rtrim(rtrim((string) $i->tax_rate_percent, '0'), '.').'%</td><td align="right">'.$money($i->line_total).'</td></tr>';
        }
        $note = $po->note ? '<p><b>Note:</b> '.e($po->note).'</p>' : '';

        return <<<HTML
<style>
 body { color:#1a2433; } h1 { color:#002756; font-size:18pt; margin:0 }
 table.items { width:100%; border-collapse:collapse; margin-top:8mm }
 table.items th { background:#002756; color:#fff; padding:4px 6px; text-align:left; font-size:8.5pt }
 table.items td { border-bottom:0.3mm solid #cfd6e0; padding:4px 6px; vertical-align:top }
 table.totals td { padding:2px 6px }
</style>
<table width="100%"><tr><td width="55%">$logo<br/><b>{$this->e($c->legal_name)}</b><br/>$addr<br/>$phones</td>
<td align="right"><h1>PURCHASE ORDER</h1><b>{$po->po_no}</b><br/>Date: {$po->order_date->toDateString()}<br/>Expected: {$this->date($po->expected_date)}<br/>Status: {$po->status}</td></tr></table>
<p style="margin-top:6mm"><b>Supplier</b><br/>{$this->e($s->name)} ({$this->e($s->code)})<br/>{$this->e((string) $s->address)}<br/>{$this->e(implode(', ', $s->phones ?? []))}</p>
<table class="items"><tr><th width="6%">#</th><th>Item</th><th width="11%" align="right">Qty</th><th width="16%" align="right">Unit cost</th><th width="9%" align="right">Tax</th><th width="17%" align="right">Total</th></tr>$rows</table>
<table class="totals" align="right" style="margin-top:4mm">
<tr><td>Subtotal</td><td align="right">{$money($po->subtotal)}</td></tr><tr><td>Tax</td><td align="right">{$money($po->tax_total)}</td></tr>
<tr><td>Other costs</td><td align="right">{$money($po->extra_total)}</td></tr><tr><td><b>Grand total</b></td><td align="right"><b>{$money($po->grand_total)}</b></td></tr></table>
<div style="clear:both"></div>$note
<table width="100%" style="margin-top:22mm"><tr><td width="50%">________________________<br/>Prepared by</td><td align="right">________________________<br/>Authorised signature</td></tr></table>
HTML;
    }

    private function e(?string $v): string
    {
        return e((string) $v);
    }

    private function date($d): string
    {
        return $d ? $d->toDateString() : '—';
    }
}
