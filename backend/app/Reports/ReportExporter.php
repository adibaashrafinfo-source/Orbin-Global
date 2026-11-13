<?php

namespace App\Reports;

use App\Models\CompanyProfile;
use App\Pdf\Colors;
use App\Pdf\PdfText;
use App\Services\Pdf\PdfFactory;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV, Excel and PDF for any report. CSV and Excel are written row by row as the report yields them, so nothing is held in memory;
 * the PDF is written to mPDF in slices of rows. Labels are English in files (the screen is translated).
 */
class ReportExporter
{
    private const SLICE = 150;

    private const NUMERIC = ['int', 'qty', 'money', 'cost', 'percent'];

    public function __construct(private readonly ReportRunner $runner) {}

    public function filename(ReportDef $def, ReportFilters $f, string $ext): string
    {
        $range = ($def->options['range'] ?? 'range') === 'range' ? $f->from.'_'.$f->to : (($def->options['range'] ?? '') === 'asof' ? $f->asOf : 'current');

        return 'Orbin_'.$def->id.'_'.$range.'.'.$ext;
    }

    public function csv(ReportDef $def, ReportFilters $f, ReportContext $ctx): StreamedResponse
    {
        $cols = $this->runner->columns($def, $ctx);

        return response()->streamDownload(function () use ($def, $f, $ctx, $cols): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the file as UTF-8
            fputcsv($out, array_map(fn (Col $c) => $c->label, $cols));
            foreach ($this->runner->stream($def, $f, $ctx) as $row) {
                fputcsv($out, array_map(fn (Col $c) => $this->safe($c, (string) ($row[$c->key] ?? '')), $cols));
            }
            $totals = $this->runner->totals($def, $f, $ctx);
            if ($totals !== []) {
                fputcsv($out, array_map(fn (Col $c, int $i) => $i === 0 ? 'Total' : (string) ($totals[$c->key] ?? ''), $cols, array_keys($cols)));
            }
            fclose($out);
        }, $this->filename($def, $f, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** A text cell that starts like a formula would be run by a spreadsheet; a leading apostrophe keeps it text. */
    private function safe(Col $c, string $v): string
    {
        return $c->type === 'text' && $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v;
    }

    public function xlsx(ReportDef $def, ReportFilters $f, ReportContext $ctx): StreamedResponse
    {
        $cols = $this->runner->columns($def, $ctx);

        return response()->streamDownload(function () use ($def, $f, $ctx, $cols): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $head = (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor(ltrim(Colors::NAVY['900'], '#'));
            $writer->addRow(new Row(array_map(fn (Col $c) => Cell::fromValue($c->label, $head), $cols)));
            $money = (new Style)->withFormat('#,##0.00');
            $qty = (new Style)->withFormat('#,##0.000');
            foreach ($this->runner->stream($def, $f, $ctx) as $row) {
                $writer->addRow(new Row(array_map(fn (Col $c) => $this->cell($c, (string) ($row[$c->key] ?? ''), $money, $qty), $cols)));
            }
            $totals = $this->runner->totals($def, $f, $ctx);
            if ($totals !== []) {
                $bold = (new Style)->withFontBold(true);
                $writer->addRow(new Row(array_map(fn (Col $c, int $i) => $i === 0 ? Cell::fromValue('Total', $bold) : $this->cell($c, (string) ($totals[$c->key] ?? ''), $money, $qty), $cols, array_keys($cols))));
            }
            $writer->close();
        }, $this->filename($def, $f, 'xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Numbers go in as numbers so Excel can add them; everything else as text. */
    private function cell(Col $c, string $v, Style $money, Style $qty): Cell
    {
        if ($v === '' || ! in_array($c->type, self::NUMERIC, true)) {
            return Cell::fromValue($v);
        }
        $n = (float) $v;

        return match ($c->type) {
            'int' => Cell::fromValue((int) $v),
            'qty' => Cell::fromValue($n, $qty),
            'percent' => Cell::fromValue($n),
            default => Cell::fromValue($n, $money),
        };
    }

    public function pdf(ReportDef $def, ReportFilters $f, ReportContext $ctx, bool $inline): Response
    {
        $cols = $this->runner->columns($def, $ctx);
        $company = CompanyProfile::current();
        $mpdf = PdfFactory::make($def->name, ['format' => 'A4-L', 'margin' => [10, 10, 10, 16]]);
        $mpdf->SetHTMLFooter(PdfFactory::footer(['tagline' => $company->tagline ?: 'Trust. Growth. Excellence.'], $def->id));
        $weights = array_map(fn (Col $c) => ['text' => 3.0, 'date' => 1.3, 'datetime' => 1.7, 'int' => 0.9, 'qty' => 1.1, 'money' => 1.5, 'cost' => 1.4, 'percent' => 1.0][$c->type] ?? 1.5, $cols);
        $sum = array_sum($weights);
        $width = array_map(fn (float $w) => round($w / $sum * 100, 2), $weights);

        $mpdf->WriteHTML($this->style().'<table width="100%"><tr><td><span class="t">'.PdfText::e($def->name).'</span><br/><span class="m">'.PdfText::e($company->legal_name ?: 'Orbin Global').' · '.PdfText::e($this->scope($def, $f)).'</span></td>'
            .'<td class="r m">'.PdfText::e(now('Asia/Dhaka')->format('d M Y, h:i A')).'</td></tr></table>');
        $buffer = [];
        $first = true;
        foreach ($this->runner->stream($def, $f, $ctx) as $row) {
            $buffer[] = $row;
            if (count($buffer) >= self::SLICE) {
                $mpdf->WriteHTML($this->table($cols, $width, $buffer, null, $first));
                $first = false;
                $buffer = [];
            }
        }
        $totals = $this->runner->totals($def, $f, $ctx);
        $mpdf->WriteHTML($this->table($cols, $width, $buffer, $totals ?: null, $first));

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$this->filename($def, $f, 'pdf').'"',
        ]);
    }

    private function scope(ReportDef $def, ReportFilters $f): string
    {
        return match ($def->options['range'] ?? 'range') {
            'asof' => 'as of '.$f->asOf,
            'none' => 'as of '.now('Asia/Dhaka')->toDateString(),
            default => $f->from.' to '.$f->to,
        };
    }

    private function style(): string
    {
        return '<style>body{font-family:inter;font-size:8pt;color:'.Colors::NEUTRAL['text'].'} .t{font-family:jakarta;font-weight:bold;font-size:15pt;color:'.Colors::NAVY['900'].'} .m{font-size:8pt;color:'.Colors::NEUTRAL['textMuted'].'} .r{text-align:right}'
            .' table.g{width:100%;border-collapse:collapse;margin-top:3mm} table.g th{background-color:'.Colors::NAVY['900'].';color:'.Colors::NEUTRAL['surface'].';font-size:7.5pt;padding:1.5mm;text-align:left}'
            .' table.g td{border-bottom:0.2mm solid '.Colors::NAVY['100'].';padding:1.4mm} table.g tr.tot td{background-color:'.Colors::NAVY['50'].';font-weight:bold;border-top:0.4mm solid '.Colors::ORANGE['500'].'}</style>';
    }

    /**
     * @param  array<int, Col>  $cols
     * @param  array<int, float>  $width
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>|null  $totals
     */
    private function table(array $cols, array $width, array $rows, ?array $totals, bool $head): string
    {
        $html = '<table class="g">';
        if ($head) {
            $html .= '<thead><tr>'.implode('', array_map(fn (Col $c, int $i) => '<th width="'.$width[$i].'%"'.(in_array($c->type, self::NUMERIC, true) ? ' class="r"' : '').'>'.PdfText::e($c->label).'</th>', $cols, array_keys($cols))).'</tr></thead>';
        }
        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>'.implode('', array_map(fn (Col $c, int $i) => $this->td($c, (string) ($row[$c->key] ?? ''), $width[$i]), $cols, array_keys($cols))).'</tr>';
        }
        if ($totals !== null) {
            $html .= '<tr class="tot">'.implode('', array_map(fn (Col $c, int $i) => $this->td($c, $i === 0 ? 'Total' : (string) ($totals[$c->key] ?? ''), $width[$i], $i === 0), $cols, array_keys($cols))).'</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function td(Col $c, string $v, float $w, bool $asText = false): string
    {
        $numeric = in_array($c->type, self::NUMERIC, true) && ! $asText;
        $text = match (true) {
            ! $numeric || $v === '' => PdfText::e($v),
            $c->type === 'money' => PdfText::money($v, false),
            $c->type === 'percent' => PdfText::e($v).'%',
            default => PdfText::e($v),
        };

        return '<td width="'.$w.'%"'.($numeric ? ' class="r"' : '').'>'.$text.'</td>';
    }
}
