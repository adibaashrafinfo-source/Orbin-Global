@php
  use App\Pdf\Colors as C; use App\Pdf\PdfText as T;
  $t = $shift->totals ?? []; $methodNames = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank_transfer' => 'Bank Transfer'];
@endphp
<html><head>@include('pdf::partials.style')</head><body>
@include('pdf::partials.header', ['company' => $company])
<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="55%"><h1 class="title">Z-REPORT</h1></td>
  <td width="45%" class="right" style="font-size:8pt"><b>{{ $shift->shift_no }}</b><br/>Cashier: {!! T::e($cashierName) !!}<br/>Opened {{ T::date($shift->opened_at, 'd M Y, h:i A') }}<br/>Closed {{ T::date($shift->closed_at, 'd M Y, h:i A') }}</td>
</tr></table>
<h3 class="section">Sales</h3>
<table class="totals">
  <tr><td>Invoices</td><td class="right">{{ $t['sales_count'] ?? 0 }}</td></tr>
  <tr><td>Total sales</td><td class="right">{!! T::money($t['sales_total'] ?? '0') !!}</td></tr>
  <tr><td>Voided invoices</td><td class="right">{{ $t['void_count'] ?? 0 }} · {!! T::money($t['void_total'] ?? '0') !!}</td></tr>
</table>
<h3 class="section">Takings by method</h3>
<table class="pay"><thead><tr><th>Method</th><th class="right">Payments</th><th class="right">Amount</th></tr></thead><tbody>
@forelse (($t['by_method'] ?? []) as $m => $row)
  <tr><td>{{ $methodNames[$m] ?? $m }}</td><td class="right">{{ $row['count'] }}</td><td class="right">{!! T::money($row['total'], false) !!}</td></tr>
@empty <tr><td colspan="3" class="muted">No payments.</td></tr> @endforelse
</tbody></table>
<h3 class="section">Cash in the drawer</h3>
<table class="totals">
  <tr><td>Opening float</td><td class="right">{!! T::money($t['opening_float'] ?? '0') !!}</td></tr>
  <tr><td>Cash sales</td><td class="right">{!! T::money($t['cash_sales'] ?? '0') !!}</td></tr>
  <tr><td>Due collections and transfers in</td><td class="right">{!! T::money($t['cash_collections'] ?? '0') !!}</td></tr>
  <tr><td>Cash refunds</td><td class="right">− {!! T::money($t['cash_refunds'] ?? '0') !!}</td></tr>
  <tr><td>Cash in</td><td class="right">{!! T::money($t['cash_in'] ?? '0') !!}</td></tr>
  <tr><td>Cash out</td><td class="right">− {!! T::money($t['cash_out'] ?? '0') !!}</td></tr>
  <tr><td>Expenses, supplier payments and transfers out</td><td class="right">− {!! T::money($t['cash_paid_out'] ?? '0') !!}</td></tr>
  <tr class="grand"><td>EXPECTED CASH</td><td class="right">{!! T::money($shift->expected_cash) !!}</td></tr>
  <tr><td>Counted cash</td><td class="right">{!! T::money($shift->counted_cash) !!}</td></tr>
  <tr class="due"><td>VARIANCE</td><td class="right">{!! T::money($shift->variance) !!}</td></tr>
</table>
<h3 class="section">Count by note and coin</h3>
<table class="pay"><thead><tr><th>Denomination</th><th class="right">Count</th><th class="right">Amount</th></tr></thead><tbody>
@foreach (($shift->denomination_counts ?? []) as $d => $n)
  @if ((int) $n > 0)<tr><td>{!! T::money($d, false) !!}</td><td class="right">{{ $n }}</td><td class="right">{!! T::money((int) $d * (int) $n, false) !!}</td></tr>@endif
@endforeach
</tbody></table>
@if ($shift->note)<p class="small muted"><b>Note:</b> {!! T::e($shift->note) !!}</p>@endif
<table width="100%" style="border-collapse:collapse;margin-top:14mm"><tr><td width="40%"><div class="sig">Cashier</div></td><td width="20%">&nbsp;</td><td width="40%"><div class="sig">Manager</div></td></tr></table>
</body></html>
