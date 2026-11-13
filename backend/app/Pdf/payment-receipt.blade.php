@php
  use App\Pdf\Colors as C; use App\Pdf\PdfText as T; use App\Support\BdtToWords;
  $methodNames = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank_transfer' => 'Bank Transfer'];
  $receive = $payment->direction === 'receive';
@endphp
<html><head>@include('pdf::partials.style')</head><body>
@include('pdf::partials.header', ['company' => $company])
<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="58%"><h1 class="title">{{ $receive ? 'PAYMENT RECEIPT' : 'PAYMENT VOUCHER' }}</h1></td>
  <td width="42%" class="right" style="font-size:8pt"><b>{{ $payment->payment_no }}</b><br/>Date {{ T::date($payment->payment_date) }}<br/>
    @if ($payment->status === 'void')<span class="pill pill-void">VOID</span>@elseif ($payment->verification === 'pending')<span class="pill pill-partial">PENDING VERIFICATION</span>@else<span class="pill pill-paid">CONFIRMED</span>@endif</td>
</tr></table>
<table width="100%" style="border-collapse:separate;border-spacing:0;margin-top:3mm"><tr>
  <td class="card" width="60%"><h4>{{ $receive ? 'RECEIVED FROM' : 'PAID TO' }}</h4><b>{!! T::e($party['name']) !!}</b> ({{ $party['code'] }})<br/>
    {!! T::e(implode(', ', array_map([T::class, 'phone'], $party['phones']))) !!}<br/>{!! T::e($party['address']) !!}</td>
  <td width="2%">&nbsp;</td>
  <td class="card" width="38%"><h4>PAYMENT</h4>{{ $methodNames[$payment->method] ?? $payment->method }} · {!! T::e($accountName) !!}<br/>
    @if ($payment->trx_id)TrxID {!! T::e($payment->trx_id) !!}<br/>@endif
    @if ($payment->sender_number)From {!! T::e(T::phone($payment->sender_number)) !!}<br/>@endif
    @if ($payment->bank_name){!! T::e($payment->bank_name) !!} · {!! T::e($payment->bank_ref) !!}@endif</td>
</tr></table>
<table class="totals" style="margin-top:4mm">
  <tr class="grand"><td>AMOUNT {{ $receive ? 'RECEIVED' : 'PAID' }}</td><td class="right">{!! T::money($payment->amount) !!}</td></tr>
</table>
<p class="words"><b>In words:</b> {{ BdtToWords::convert((string) $payment->amount) }}</p>
<h3 class="section">Applied to</h3>
<table class="pay"><thead><tr><th>Document</th><th class="right">Amount</th></tr></thead><tbody>
@forelse ($allocations as $a)
  <tr><td>{!! T::e($a['no']) !!}</td><td class="right">{!! T::money($a['amount'], false) !!}</td></tr>
@empty <tr><td colspan="2" class="muted">Nothing was due, so the whole amount is kept as an advance.</td></tr> @endforelse
@if ((float) $payment->advance_amount > 0 && count($allocations) > 0)
  <tr><td>Kept as advance</td><td class="right">{!! T::money($payment->advance_amount, false) !!}</td></tr>
@endif
</tbody></table>
<table class="totals" style="margin-top:3mm"><tr class="due"><td>{{ $receive ? 'BALANCE DUE FROM YOU AFTER THIS PAYMENT' : 'BALANCE WE OWE AFTER THIS PAYMENT' }}</td><td class="right">{!! T::money($balanceAfter) !!}</td></tr></table>
@if ($payment->note)<p class="small muted"><b>Note:</b> {!! T::e($payment->note) !!}</p>@endif
@if ($payment->status === 'void')<p class="small"><b>Voided:</b> {!! T::e($payment->void_reason) !!}</p>@endif
<table width="100%" style="border-collapse:collapse;margin-top:18mm"><tr><td width="40%"><div class="sig">{{ $receive ? 'Received by' : 'Paid by' }}</div></td><td width="20%">&nbsp;</td><td width="40%"><div class="sig">{{ $receive ? 'Customer signature' : 'Supplier signature' }}</div></td></tr></table>
</body></html>
