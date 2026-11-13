@php
  use App\Pdf\Colors as C; use App\Pdf\PdfText as T;
  $customer = $type === 'customer';
@endphp
@if ($first)
<html><head>@include('pdf::partials.style')</head><body>
@include('pdf::partials.header', ['company' => $company])
<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="58%"><h1 class="title">{{ $customer ? 'CUSTOMER STATEMENT' : 'SUPPLIER STATEMENT' }}</h1></td>
  <td width="42%" class="right" style="font-size:8pt">{{ $from ? T::date($from) : 'Beginning' }} to {{ $to ? T::date($to) : T::date(now('Asia/Dhaka')) }}</td>
</tr></table>
<table width="100%" style="border-collapse:separate;border-spacing:0;margin-top:3mm"><tr>
  <td class="card"><h4>{{ $customer ? 'ACCOUNT OF' : 'SUPPLIER' }}</h4><b>{!! T::e($party['name']) !!}</b> ({{ $party['code'] }})<br/>
    {!! T::e(implode(', ', array_map([T::class, 'phone'], $party['phones']))) !!}<br/>{!! T::e($party['address']) !!}</td>
</tr></table>
@endif
<table class="items"@if (! $first) style="margin-top:0"@endif>
@if ($first)<thead><tr><th width="13%">Date</th><th width="16%">Reference</th><th>Description</th><th width="14%" class="right">Debit</th><th width="14%" class="right">Credit</th><th width="15%" class="right">Balance</th></tr></thead>@endif<tbody>
@if ($first)  <tr><td colspan="5"><b>Opening balance</b></td><td class="right"><b>{!! T::money($opening, false) !!}</b></td></tr>@endif
@foreach ($rows as $r)
  <tr><td width="13%">{{ T::date($r['date']) }}</td><td width="16%">{!! T::e($r['ref_no'] ?? '') !!}</td><td>{!! T::e($r['description'] ?? ucfirst($r['type'])) !!}</td>
    <td class="right" width="14%">{!! (float) $r['debit'] > 0 ? T::money($r['debit'], false) : '' !!}</td><td class="right" width="14%">{!! (float) $r['credit'] > 0 ? T::money($r['credit'], false) : '' !!}</td><td class="right" width="15%">{!! T::money($r['balance'], false) !!}</td></tr>
@endforeach
</tbody></table>
@if ($last)
<table class="totals" style="margin-top:4mm">
  <tr><td>Total debit</td><td class="right">{!! T::money($totalDebit) !!}</td></tr>
  <tr><td>Total credit</td><td class="right">{!! T::money($totalCredit) !!}</td></tr>
  <tr class="grand"><td>{{ $customer ? 'AMOUNT DUE FROM YOU' : 'AMOUNT WE OWE' }}</td><td class="right">{!! T::money($closing) !!}</td></tr>
</table>
<p class="small muted">{{ $customer ? 'A debit is an amount you were billed; a credit is a payment, return or adjustment in your favour. A negative balance is an advance.' : 'A credit is an amount we were billed; a debit is a payment, return or discount. A negative balance is an advance we paid.' }}</p>
</body></html>
@endif
