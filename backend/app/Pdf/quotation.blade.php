@php
  use App\Pdf\Colors as C; use App\Pdf\PdfText as T; use App\Support\BdtToWords;
  $co = $company; $cu = $quotation->customer_snapshot; $ve = $quotation->vehicle_snapshot;
@endphp
<html><head>@include('pdf::partials.style')</head><body>
@include('pdf::partials.header', ['company' => $co])
<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="62%"><h1 class="title">QUOTATION</h1></td>
  <td width="38%" class="right" style="font-size:8pt"><b>{{ $quotation->quote_no }}</b><br/>{{ T::date($quotation->quote_date) }}<br/>@if ($quotation->valid_until)Valid until <b>{{ T::date($quotation->valid_until) }}</b>@endif</td>
</tr></table>
<table width="100%" style="border-collapse:separate;border-spacing:0;margin-top:3mm"><tr>
  <td width="58%" valign="top" class="card"><h4>PREPARED FOR</h4><b>{!! T::e($cu['name'] ?? '') !!}</b><br/>
    @if (empty($cu['is_walk_in'])){!! T::e(implode(', ', array_map([T::class, 'phone'], $cu['phones'] ?? []))) !!}@endif</td>
  <td width="2%">&nbsp;</td>
  <td width="40%" valign="top" class="card"><h4>VEHICLE</h4>@if ($ve){!! T::e(trim(($ve['reg_no'] ?? '').' '.($ve['make'] ?? '').' '.($ve['model'] ?? '').' '.($ve['year'] ?? ''))) !!}@else<span class="muted">—</span>@endif</td>
</tr></table>
<table class="items"><thead><tr><th width="5%">SL</th><th width="45%">Item</th><th width="8%" class="right">Qty</th><th width="15%" class="right">Unit price</th><th width="10%" class="right">VAT %</th><th width="17%" class="right">Amount</th></tr></thead><tbody>
@foreach ($quotation->items as $n => $it)
  <tr><td>{{ $n + 1 }}</td><td>{!! T::e($it->description_snapshot) !!}</td><td class="right">{{ T::qty($it->qty) }}</td><td class="right">{!! T::money($it->unit_price, false) !!}</td>
    <td class="right">{{ T::qty($it->tax_rate_percent) }}</td><td class="right"><b>{!! T::money($it->line_total, false) !!}</b></td></tr>
@endforeach
</tbody></table>
<div style="page-break-inside:avoid">
<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="52%" valign="top"><div class="words"><b>In words:</b> {{ BdtToWords::convert((string) $quotation->grand_total) }}</div>
    @if ($quotation->note)<p class="small muted">{!! nl2br(T::e($quotation->note)) !!}</p>@endif
    <p class="small muted">This is an offer, not an invoice. Stock is not reserved and prices hold until the date above.</p></td>
  <td width="3%">&nbsp;</td>
  <td width="45%"><table class="totals">
    <tr><td>Subtotal</td><td class="right">{!! T::money($quotation->subtotal) !!}</td></tr>
    @if ((float) $quotation->discount_total > 0)<tr><td>Discount</td><td class="right">− {!! T::money($quotation->discount_total) !!}</td></tr>@endif
    <tr><td>VAT</td><td class="right">{!! T::money($quotation->tax_total) !!}</td></tr>
    @if ((float) $quotation->delivery_charge > 0)<tr><td>Delivery</td><td class="right">{!! T::money($quotation->delivery_charge) !!}</td></tr>@endif
    <tr class="grand"><td>TOTAL</td><td class="right">{!! T::money($quotation->grand_total) !!}</td></tr>
  </table></td>
</tr></table>
@if (!empty($co['invoice_terms']))<h3 class="section">Terms</h3><div class="small muted">{!! nl2br(T::e($co['invoice_terms'])) !!}</div>@endif
</div>
</body></html>
