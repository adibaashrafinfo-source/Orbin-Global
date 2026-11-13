<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\Sale;
use App\Pdf\Colors;
use App\Pdf\PdfText;
use Illuminate\Http\Response;

/** FR-906: the page behind the QR code. It shows the invoice number, date, total and whether it is paid, and nothing about people. */
class VerifyController extends Controller
{
    public function __invoke(string $token): Response
    {
        $sale = strlen($token) >= 20 ? Sale::query()->where('verify_token', $token)->whereNotNull('invoice_no')->first() : null;
        if ($sale === null) {
            return response($this->page('Invoice not found', '<p>This code does not match any invoice issued by us.</p>', 'miss'), 404);
        }
        $status = $sale->status === 'void' ? 'VOID' : strtoupper($sale->payment_status);
        $rows = [
            'Invoice' => $sale->invoice_no, 'Date' => PdfText::date($sale->sale_date, 'd M Y'), 'Total' => 'BDT '.number_format((float) $sale->grand_total, 2), 'Status' => $status,
        ];
        $html = '<table>'.implode('', array_map(fn ($k, $v) => '<tr><th>'.e($k).'</th><td>'.e($v).'</td></tr>', array_keys($rows), $rows)).'</table>';

        return response($this->page('Invoice verified', $html, $sale->status === 'void' ? 'void' : 'ok'))->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    private function page(string $title, string $body, string $state): string
    {
        $company = e((string) (CompanyProfile::current()->legal_name ?: 'Orbin Global'));

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.e($title).' · '.$company.'</title><style>'
            .'body{margin:0;font-family:system-ui,sans-serif;background:'.Colors::NAVY['50'].';color:'.Colors::NEUTRAL['text'].';display:grid;place-items:center;min-height:100vh}'
            .'main{background:'.Colors::NEUTRAL['surface'].';border-radius:12px;padding:28px 32px;max-width:420px;width:calc(100% - 32px);border:1px solid '.Colors::NAVY['100'].'}'
            .'h1{font-size:20px;margin:0 0 4px;color:'.Colors::NAVY['900'].'}p.co{margin:0 0 18px;color:'.Colors::NEUTRAL['textMuted'].';font-size:14px}'
            .'table{width:100%;border-collapse:collapse}th{text-align:left;color:'.Colors::NEUTRAL['textMuted'].';font-weight:500;padding:8px 0}td{text-align:right;font-weight:600;padding:8px 0}tr{border-top:1px solid '.Colors::NAVY['100'].'}'
            .'</style></head><body data-state="'.$state.'"><main><h1>'.e($title).'</h1><p class="co">'.$company.'</p>'.$body.'</main></body></html>';
    }
}
