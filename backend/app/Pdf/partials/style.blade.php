@php use App\Pdf\Colors as C; @endphp
<style>
  body { font-family: inter; font-size: 8.5pt; color: {{ C::NEUTRAL['text'] }}; }
  .bn { font-family: bangla; }
  h1.title, span.title { font-family: jakarta; font-weight: bold; font-size: 22pt; color: {{ C::NAVY['900'] }}; margin: 0; }
  .muted { color: {{ C::NEUTRAL['textMuted'] }}; }
  .small { font-size: 7.5pt; }
  .right { text-align: right; }
  .pill { font-size: 7.5pt; font-weight: bold; padding: 1mm 3mm; }
  .pill-paid { background-color: {{ C::NAVY['100'] }}; color: {{ C::NAVY['900'] }}; }
  .pill-partial { background-color: {{ C::ORANGE['100'] }}; color: {{ C::ORANGE['800'] }}; }
  .pill-due { background-color: {{ C::ORANGE['200'] }}; color: {{ C::ORANGE['800'] }}; }
  .pill-void { background-color: {{ C::SEMANTIC['danger'] }}; color: {{ C::NEUTRAL['surface'] }}; }
  .card { background-color: {{ C::NAVY['50'] }}; padding: 3mm; }
  .card h4 { margin: 0 0 1mm 0; font-size: 7.5pt; color: {{ C::NAVY['600'] }}; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 4mm; }
  table.items th { background-color: {{ C::NAVY['900'] }}; color: {{ C::NEUTRAL['surface'] }}; font-size: 7.5pt; padding: 2mm 1.5mm; text-align: left; }
  table.items td { border-bottom: 0.2mm solid {{ C::NAVY['100'] }}; padding: 1.8mm 1.5mm; vertical-align: top; }
  table.items tr { page-break-inside: avoid; }
  table.totals { width: 100%; border-collapse: collapse; }
  table.totals td { padding: 1.2mm 2mm; }
  table.totals tr.grand td { background-color: {{ C::NAVY['900'] }}; color: {{ C::NEUTRAL['surface'] }}; font-size: 11pt; font-weight: bold; }
  table.totals tr.due td { background-color: {{ C::ORANGE['50'] }}; color: {{ C::ORANGE['700'] }}; font-weight: bold; }
  table.pay { width: 100%; border-collapse: collapse; margin-top: 2mm; }
  table.pay th { background-color: {{ C::NAVY['100'] }}; color: {{ C::NAVY['900'] }}; font-size: 7.5pt; padding: 1.5mm; text-align: left; }
  table.pay td { border-bottom: 0.2mm solid {{ C::NAVY['100'] }}; padding: 1.5mm; }
  .sig { border-top: 0.3mm solid {{ C::NEUTRAL['textSubtle'] }}; padding-top: 1mm; font-size: 7.5pt; color: {{ C::NEUTRAL['textMuted'] }}; text-align: center; }
  .words { font-style: italic; color: {{ C::NAVY['900'] }}; }
  h3.section { font-family: jakarta; font-size: 8.5pt; color: {{ C::NAVY['900'] }}; margin: 4mm 0 1mm 0; }
</style>
