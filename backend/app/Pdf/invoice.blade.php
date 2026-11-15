@php
  use App\Pdf\Colors as C; use App\Pdf\PdfText as T; use App\Support\BdtToWords;
  $co = $sale->company_snapshot; $cu = $sale->customer_snapshot; $ve = $sale->vehicle_snapshot;
  $due = (float) $sale->due_total > 0;
  $isVoid = $sale->status === 'void';
  [$pillClass, $pillText] = $isVoid ? ['pill-void', 'VOID'] : match ($sale->payment_status) { 'paid' => ['pill-paid', 'PAID'], 'partial' => ['pill-partial', 'PARTIAL'], default => ['pill-due', 'DUE'] };
  $parents = $items->whereNull('bundle_parent_line');
  $methodNames = ['cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank_transfer' => 'Bank Transfer'];
  $custAddr = array_filter([$cu['address_line1'] ?? '', $cu['address_line2'] ?? '', trim(($cu['area'] ?? '').' '.($cu['district'] ?? '').' '.($cu['postcode'] ?? ''))]);
@endphp
<html><head>@include('pdf::partials.style')</head><body>
@include('pdf::partials.header', ['company' => $co])

<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="62%" valign="middle"><span class="title">INVOICE</span> &nbsp; <span class="pill {{ $pillClass }}">{{ $pillText }}</span></td>
  <td width="38%" class="right" valign="middle"><barcode code="{{ $verifyUrl }}" type="QR" class="barcode" size="0.55" error="M" disableborder="1" /></td>
</tr></table>

<table width="100%" style="border-collapse:collapse;margin-top:2mm;font-size:8pt"><tr>
  <td width="50%" valign="top">
    <b>Invoice no.</b> {{ $sale->invoice_no }}<br/>
    <b>Date</b> {{ T::date($sale->sale_date, 'd M Y, h:i A') }}<br/>
    @if ($sale->due_date)<b>Due date</b> {{ T::date($sale->due_date) }}<br/>@endif
    <b>Channel</b> {{ ucfirst(str_replace('_', ' ', $sale->sale_channel)) }}
  </td>
  <td width="50%" valign="top">
    <b>Branch</b> {!! T::e($branchName) !!}<br/>
    <b>Cashier</b> {!! T::e($cashierName) !!}@if ($salespersonName && $salespersonName !== $cashierName) · <b>Sales</b> {!! T::e($salespersonName) !!}@endif<br/>
    @if ($shiftNo)<b>Shift</b> {{ $shiftNo }}<br/>@endif
    <b>Payment</b> {{ ucfirst($sale->payment_status) }}@if ($payments->isNotEmpty()) · {{ $payments->pluck('method')->unique()->map(fn ($m) => $methodNames[$m] ?? $m)->implode(', ') }}@endif
  </td>
</tr></table>

<table width="100%" style="border-collapse:separate;border-spacing:0;margin-top:3mm"><tr>
  <td width="58%" valign="top" class="card">
    <h4>BILL TO</h4>
    <b>{!! T::e($cu['name'] ?? '') !!}</b> @if (!empty($cu['code']) && empty($cu['is_walk_in']))<span class="muted small">({{ $cu['code'] }})</span>@endif<br/>
    @if (!empty($cu['company_name'])){!! T::e($cu['company_name']) !!}<br/>@endif
    @if (empty($cu['is_walk_in']))
      @if (!empty($cu['phones'])){!! T::e(implode(', ', array_map([T::class, 'phone'], $cu['phones']))) !!}<br/>@endif
      @if (!empty($cu['email'])){!! T::e($cu['email']) !!}<br/>@endif
      @if ($custAddr){!! T::e(implode(', ', $custAddr)) !!}<br/>@endif
      @if (!empty($cu['bin_no']))BIN {!! T::e($cu['bin_no']) !!} @endif @if (!empty($cu['nid_no']))NID {!! T::e($cu['nid_no']) !!}@endif
    @endif
  </td>
  <td width="2%">&nbsp;</td>
  <td width="40%" valign="top" class="card">
    <h4>VEHICLE</h4>
    @if ($ve)
      @if (!empty($ve['reg_no']))<b>{!! T::e($ve['reg_no']) !!}</b><br/>@endif
      {!! T::e(trim(($ve['make'] ?? '').' '.($ve['model'] ?? '').' '.($ve['year'] ?? ''))) !!}<br/>
      @if (!empty($ve['color'])){!! T::e($ve['color']) !!}@endif
    @else<span class="muted">—</span>@endif
  </td>
</tr></table>

<table class="items" repeatHeader="1">
  <thead><tr>
    <th width="5%">SL</th><th width="37%">Item</th><th width="10%">Warranty</th><th width="7%" class="right">Qty</th>
    <th width="13%" class="right">Unit price</th><th width="10%" class="right">Disc.</th><th width="8%" class="right">VAT</th><th width="14%" class="right">Amount</th>
  </tr></thead>
  <tbody>
  @foreach ($parents as $n => $it)
    @php
      $s = $it->spec_snapshot;
      $size = $s['size_label'] ?? null;
      $spec = array_filter([ucfirst((string) ($s['position'] ?? '')), $size, $s['glass_type'] ?? null, $s['color_finish'] ?? null, $s['adjustment'] ? ucfirst($s['adjustment']) : null, implode(', ', array_map(fn ($f) => ucfirst(str_replace('_', ' ', $f)), $s['features'] ?? [])) ?: null]);
      $disc = (float) $it->discount_amount + (float) $it->cart_discount_share;
      $parts = $items->where('bundle_parent_line', $it->line_no);
    @endphp
    <tr>
      <td>{{ $n + 1 }}</td>
      <td><b>{!! T::e($it->product_name_snapshot) !!}</b><br/><span class="muted small">{!! T::e(implode(' · ', $spec)) !!}@if ($spec) · @endif{{ $it->sku_snapshot }}</span>
        @if ($parts->isNotEmpty())<br/><span class="muted small">Includes: {!! T::e($parts->map(fn ($p) => T::qty($p->qty).' × '.$p->sku_snapshot)->implode(', ')) !!}</span>@endif
        @if ($it->note)<br/><span class="small"><i>{!! T::e($it->note) !!}</i></span>@endif
      </td>
      <td class="small">@if ($it->warranty_months > 0){{ $it->warranty_months }} mo<br/><span class="muted">to {{ T::date($it->warranty_until) }}</span>@else — @endif</td>
      <td class="right">{{ T::qty($it->qty) }}</td>
      <td class="right">{!! T::money($it->unit_price, false) !!}</td>
      <td class="right">{!! $disc > 0 ? T::money(number_format($disc, 2, '.', ''), false) : '—' !!}</td>
      <td class="right">{!! (float) $it->tax_amount > 0 ? T::money($it->tax_amount, false) : '—' !!}</td>
      <td class="right"><b>{!! T::money($it->line_total, false) !!}</b></td>
    </tr>
  @endforeach
  </tbody>
</table>

<div style="page-break-inside:avoid">
<table width="100%" style="border-collapse:collapse;margin-top:4mm"><tr>
  <td width="52%" valign="top">
    <div class="words"><b>In words:</b> {{ BdtToWords::convert((string) $sale->grand_total) }}</div>
@if ($sale->note)<div class="words"><b>Note:</b> {!! T::e($sale->note) !!}</div>@endif
    @if ($payments->isNotEmpty())
    <h3 class="section">Payments</h3>
    <table class="pay"><thead><tr><th>Method</th><th>Reference</th><th>Into</th><th>Date</th><th class="right">Amount</th></tr></thead><tbody>
      @foreach ($payments as $p)
        <tr><td>{{ $methodNames[$p->method] ?? $p->method }}</td>
          <td class="small">@if ($p->trx_id){{ $p->trx_id }}<br/><span class="muted">from {{ $p->sender_number }}</span>@elseif ($p->bank_ref){{ $p->bank_name }} · {{ $p->bank_ref }}@else — @endif</td>
          <td class="small">{!! T::e($accountNames[$p->account_id] ?? '') !!}</td><td class="small">{{ T::date($p->received_at, 'd M, h:i A') }}</td><td class="right">{!! T::money($p->amount, false) !!}</td></tr>
      @endforeach
    </tbody></table>
    @endif
  </td>
  <td width="3%">&nbsp;</td>
  <td width="45%" valign="top">
    <table class="totals">
      <tr><td>Subtotal</td><td class="right">{!! T::money($sale->subtotal) !!}</td></tr>
      @php $allDisc = (float) $sale->line_discount_total + (float) $sale->cart_discount_amount; @endphp
      @if ($allDisc > 0)<tr><td>Discount</td><td class="right">− {!! T::money(number_format($allDisc, 2, '.', '')) !!}</td></tr>@endif
      <tr><td>Taxable amount</td><td class="right">{!! T::money($sale->taxable_amount) !!}</td></tr>
      <tr><td>VAT</td><td class="right">{!! T::money($sale->tax_total) !!}</td></tr>
      @if ((float) $sale->delivery_charge > 0)<tr><td>Delivery / handling</td><td class="right">{!! T::money($sale->delivery_charge) !!}</td></tr>@endif
      @if ((float) $sale->round_off != 0)<tr><td>Round-off</td><td class="right">{!! T::money($sale->round_off) !!}</td></tr>@endif
      <tr class="grand"><td>GRAND TOTAL</td><td class="right">{!! T::money($sale->grand_total) !!}</td></tr>
      <tr><td>Paid</td><td class="right">{!! T::money($sale->paid_total) !!}</td></tr>
      @if ((float) $sale->change_given > 0)<tr><td class="muted">Change returned</td><td class="right muted">{!! T::money($sale->change_given) !!}</td></tr>@endif
      @if ($due)<tr class="due"><td>DUE</td><td class="right">{!! T::money($sale->due_total) !!}</td></tr>@endif
    </table>
  </td>
</tr></table>
</div>

@if (!empty($co['invoice_terms']) || !empty($co['return_policy_text']) || !empty($co['warranty_policy_text']))
<div style="page-break-inside:avoid">
<h3 class="section">Terms &amp; warranty</h3>
<div class="small muted">
  @if (!empty($co['invoice_terms'])){!! nl2br(T::e($co['invoice_terms'])) !!}<br/>@endif
  @if (!empty($co['return_policy_text'])){!! nl2br(T::e($co['return_policy_text'])) !!}<br/>@endif
  @if (!empty($co['warranty_policy_text'])){!! nl2br(T::e($co['warranty_policy_text'])) !!}@endif
</div></div>
@endif

@if ($due && (!empty($co['bank_account_no'])))
<div style="page-break-inside:avoid" class="small"><h3 class="section">Bank details for payment</h3>
  {!! T::e($co['bank_account_name'] ?? '') !!} · {!! T::e($co['bank_name'] ?? '') !!} {!! T::e($co['bank_branch'] ?? '') !!} · A/C {!! T::e($co['bank_account_no']) !!}
</div>
@endif

@if ($isVoid && $sale->void_reason)<p class="small"><b>Void reason:</b> {!! T::e($sale->void_reason) !!}</p>@endif

<div style="page-break-inside:avoid">
<table width="100%" style="border-collapse:collapse;margin-top:14mm"><tr>
  <td width="40%"><div class="sig">Customer's signature</div></td><td width="20%">&nbsp;</td>
  <td width="40%" align="center">@if ($signature)<img src="{{ $signature }}" style="height:12mm"/><br/>@endif<div class="sig">Authorized signature</div></td>
</tr></table>
</div>
</body></html>
