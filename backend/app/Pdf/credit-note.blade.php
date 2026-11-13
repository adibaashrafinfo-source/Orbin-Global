@php
  use App\Pdf\Colors as C; use App\Pdf\PdfText as T; use App\Support\BdtToWords;
  $co = $sale->company_snapshot; $cu = $sale->customer_snapshot;
  $total = number_format((float) $return->refund_total + (float) $return->ledger_credit_total, 2, '.', '');
  $methodNames = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank_transfer' => 'Bank Transfer'];
@endphp
<html><head>@include('pdf::partials.style')</head><body>
@include('pdf::partials.header', ['company' => $co])
<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="62%"><h1 class="title">CREDIT NOTE</h1></td>
  <td width="38%" class="right" style="font-size:8pt"><b>{{ $return->return_no }}</b><br/>{{ T::date($return->return_date, 'd M Y, h:i A') }}<br/>Against invoice {{ $sale->invoice_no }}</td>
</tr></table>
<table width="100%" style="border-collapse:separate;border-spacing:0;margin-top:3mm"><tr>
  <td class="card"><h4>CREDITED TO</h4><b>{!! T::e($cu['name'] ?? '') !!}</b>@if (!empty($cu['company_name'])) · {!! T::e($cu['company_name']) !!}@endif<br/>
    @if (empty($cu['is_walk_in'])){!! T::e(implode(', ', array_map([T::class, 'phone'], $cu['phones'] ?? []))) !!}@endif</td>
</tr></table>
<table class="items"><thead><tr><th width="5%">SL</th><th width="45%">Item</th><th width="15%">Condition</th><th width="8%" class="right">Qty</th><th width="12%" class="right">VAT</th><th width="15%" class="right">Amount</th></tr></thead><tbody>
@foreach ($lines as $n => $l)
  <tr><td>{{ $n + 1 }}</td><td><b>{!! T::e($l['name']) !!}</b><br/><span class="muted small">{{ $l['sku'] }}</span></td><td>{{ ucfirst($l['condition']) }}</td>
    <td class="right">{{ T::qty($l['qty']) }}</td><td class="right">{!! T::money($l['tax'], false) !!}</td><td class="right"><b>{!! T::money($l['total'], false) !!}</b></td></tr>
@endforeach
</tbody></table>
<div style="page-break-inside:avoid">
<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="52%" valign="top"><div class="words"><b>In words:</b> {{ BdtToWords::convert($total) }}</div>
    @if ($return->note)<p class="small muted">{!! T::e($return->note) !!}</p>@endif</td>
  <td width="3%">&nbsp;</td>
  <td width="45%"><table class="totals">
    <tr><td>Goods value</td><td class="right">{!! T::money($return->subtotal) !!}</td></tr>
    <tr><td>VAT</td><td class="right">{!! T::money($return->tax_total) !!}</td></tr>
    <tr class="grand"><td>CREDIT TOTAL</td><td class="right">{!! T::money($total) !!}</td></tr>
    <tr><td>Refunded now @if ($return->refund_method) ({{ $methodNames[$return->refund_method] ?? $return->refund_method }})@endif</td><td class="right">{!! T::money($return->refund_total) !!}</td></tr>
    <tr><td>Credited to account balance</td><td class="right">{!! T::money($return->ledger_credit_total) !!}</td></tr>
  </table></td>
</tr></table>
<table width="100%" style="border-collapse:collapse;margin-top:14mm"><tr><td width="40%"><div class="sig">Customer's signature</div></td><td width="20%">&nbsp;</td><td width="40%"><div class="sig">Authorized signature</div></td></tr></table>
</div>
</body></html>
