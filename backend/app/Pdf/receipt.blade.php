@php
  use App\Pdf\Colors as C; use App\Pdf\PdfText as T;
  $co = $sale->company_snapshot; $cu = $sale->customer_snapshot; $ve = $sale->vehicle_snapshot;
  $logo = public_path('assets/brand/orbin-logo.jpg');
  $methodNames = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank_transfer' => 'Bank'];
  $parents = $items->whereNull('bundle_parent_line');
  $line = fn () => '<div style="border-top:0.2mm dashed '.C::NEUTRAL['textSubtle'].';margin:1.5mm 0"></div>';
@endphp
<html><head><style>
  body { font-family: inter; font-size: 7.5pt; color: {{ C::NEUTRAL['text'] }}; }
  .bn { font-family: bangla; } .c { text-align: center; } .r { text-align: right; } .m { color: {{ C::NEUTRAL['textMuted'] }}; }
  td { padding: 0.4mm 0; vertical-align: top; }
</style></head><body>
<div class="c">
  @if (is_file($logo))<img src="data:image/jpeg;base64,{{ base64_encode((string) file_get_contents($logo)) }}" style="width:40mm" /><br/>@endif
  <b>{!! T::e($co['legal_name'] ?? '') !!}</b><br/>
  {!! T::e(trim(($co['address_line1'] ?? '').' '.($co['city'] ?? ''))) !!}<br/>
  {!! T::e(implode(', ', array_map([T::class, 'phone'], $co['phones'] ?? []))) !!}
  @if (!empty($co['bin_no']))<br/>BIN {!! T::e($co['bin_no']) !!}@endif
</div>
{!! $line() !!}
<b>{{ $sale->invoice_no }}</b>@if ($sale->status === 'void') <b style="color:{{ C::SEMANTIC['danger'] }}">VOID</b>@endif<br/>
{{ T::date($sale->sale_date, 'd M Y, h:i A') }} · {!! T::e($cashierName) !!}<br/>
@if (empty($cu['is_walk_in'])){!! T::e($cu['name'] ?? '') !!} {!! T::e(implode(', ', $cu['phones'] ?? [])) !!}<br/>@endif
@if ($ve && !empty($ve['reg_no']))Vehicle {!! T::e($ve['reg_no']) !!} {!! T::e(trim(($ve['make'] ?? '').' '.($ve['model'] ?? ''))) !!}<br/>@endif
{!! $line() !!}
<table width="100%" style="border-collapse:collapse">
@foreach ($parents as $it)
  <tr><td colspan="2"><b>{!! T::e($it->product_name_snapshot) !!}</b><br/><span class="m">{{ $it->sku_snapshot }}@if (!empty($it->spec_snapshot['size_label'])) · {{ $it->spec_snapshot['size_label'] }}@endif</span></td></tr>
  <tr><td>{{ T::qty($it->qty) }} × {!! T::money($it->unit_price, false) !!}</td><td class="r"><b>{!! T::money($it->line_total, false) !!}</b></td></tr>
@endforeach
</table>
{!! $line() !!}
<table width="100%" style="border-collapse:collapse">
  <tr><td>Subtotal</td><td class="r">{!! T::money($sale->subtotal) !!}</td></tr>
  @php $allDisc = (float) $sale->line_discount_total + (float) $sale->cart_discount_amount; @endphp
  @if ($allDisc > 0)<tr><td>Discount</td><td class="r">− {!! T::money(number_format($allDisc, 2, '.', '')) !!}</td></tr>@endif
  @if ((float) $sale->tax_total > 0)<tr><td>VAT</td><td class="r">{!! T::money($sale->tax_total) !!}</td></tr>@endif
  @if ((float) $sale->delivery_charge > 0)<tr><td>Delivery</td><td class="r">{!! T::money($sale->delivery_charge) !!}</td></tr>@endif
  @if ((float) $sale->round_off != 0)<tr><td>Round-off</td><td class="r">{!! T::money($sale->round_off) !!}</td></tr>@endif
  <tr><td style="font-size:10pt"><b>TOTAL</b></td><td class="r" style="font-size:10pt"><b>{!! T::money($sale->grand_total) !!}</b></td></tr>
</table>
{!! $line() !!}
<table width="100%" style="border-collapse:collapse">
@foreach ($payments as $p)
  <tr><td>{{ $methodNames[$p->method] ?? $p->method }}@if ($p->trx_id) <span class="m">{{ $p->trx_id }}</span>@elseif ($p->bank_ref) <span class="m">{{ $p->bank_ref }}</span>@endif</td><td class="r">{!! T::money($p->amount, false) !!}</td></tr>
@endforeach
  <tr><td>Paid</td><td class="r">{!! T::money($sale->paid_total) !!}</td></tr>
  @if ((float) $sale->change_given > 0)<tr><td>Change</td><td class="r">{!! T::money($sale->change_given) !!}</td></tr>@endif
  @if ((float) $sale->due_total > 0)<tr><td><b>DUE</b></td><td class="r"><b>{!! T::money($sale->due_total) !!}</b></td></tr>@endif
</table>
<div class="c" style="margin-top:2mm"><barcode code="{{ $verifyUrl }}" type="QR" class="barcode" size="0.6" error="M" disableborder="1" /></div>
<div class="c m" style="margin-top:1mm;font-size:7pt"><i>{!! T::e($co['tagline'] ?? 'Trust. Growth. Excellence.') !!}</i><br/>Thank you for shopping with us.</div>
</body></html>
