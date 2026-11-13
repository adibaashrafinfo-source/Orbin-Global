@php use App\Pdf\Colors as C; use App\Pdf\PdfText as T; @endphp
@php
  $co = $company;
  $logo = public_path('assets/brand/orbin-logo.jpg');
  $addr = array_filter([$co['address_line1'] ?? '', $co['address_line2'] ?? '', trim(($co['city'] ?? '').' '.($co['district'] ?? '').' '.($co['postcode'] ?? ''))]);
@endphp
<table width="100%" style="border-collapse:collapse"><tr>
  <td width="52%" valign="top">@if (is_file($logo))<img src="data:image/jpeg;base64,{{ base64_encode((string) file_get_contents($logo)) }}" style="width:48mm" />@endif</td>
  <td width="48%" valign="top" class="right" style="font-size:8pt">
    <b style="font-family:jakarta;font-size:11pt;color:{{ C::NAVY['900'] }}">{!! T::e($co['legal_name'] ?? '') !!}</b><br/>
    @if ($addr){!! T::e(implode(', ', $addr)) !!}<br/>@endif
    @if (!empty($co['phones'])){!! T::e(implode(', ', array_map([T::class, 'phone'], $co['phones']))) !!}<br/>@endif
    @if (!empty($co['whatsapp']))WhatsApp {!! T::e($co['whatsapp']) !!}<br/>@endif
    @if (!empty($co['email'])){!! T::e($co['email']) !!}@endif @if (!empty($co['website'])) · {!! T::e($co['website']) !!}@endif<br/>
    @if (!empty($co['bin_no']))BIN/VAT {!! T::e($co['bin_no']) !!} @endif
    @if (!empty($co['tin_no']))· TIN {!! T::e($co['tin_no']) !!} @endif
    @if (!empty($co['trade_license_no']))· Trade licence {!! T::e($co['trade_license_no']) !!}@endif
  </td>
</tr></table>
<table width="100%" style="border-collapse:collapse;margin-top:2mm"><tr>
  <td width="14%" style="background-color:{{ C::NAVY['900'] }};height:1.2mm;font-size:1pt">&nbsp;</td>
  <td width="86%" style="background-color:{{ C::ORANGE['500'] }};height:1.2mm;font-size:1pt">&nbsp;</td>
</tr></table>
