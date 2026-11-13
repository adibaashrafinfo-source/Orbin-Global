<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportRegistry;
use OpenSpout\Reader\XLSX\Reader;

/** Gate: every report exports CSV, Excel and PDF, and each file carries exactly the rows the screen shows. */
class ReportExportTest extends ReportsTestCase
{
    /** @return array<int, string> */
    private function ids(): array
    {
        return array_keys(app(ReportRegistry::class)->all());
    }

    /** Parameters that make the reports needing a pick (a variant) produce something. @return array<string, mixed> */
    private function query(string $id): array
    {
        return ['from' => $this->today, 'to' => $this->today, 'as_of' => $this->today, 'per_page' => 200, 'variant_id' => in_array($id, ['stock-card', 'supplier-price-trend'], true) ? $this->variant : null];
    }

    private function pdfText(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rep');
        file_put_contents($tmp, $pdf);
        $out = (string) shell_exec('pdftotext -layout '.escapeshellarg($tmp).' - 2>&1');
        @unlink($tmp);

        return $out;
    }

    /** @return array<int, array<int, mixed>> */
    private function xlsxRows(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($tmp, $bytes);
        $reader = new Reader;
        $reader->open($tmp);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($tmp);

        return $rows;
    }

    public function test_the_catalog_is_the_forty_one_reports_of_the_spec(): void
    {
        $spec = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/orbin-pos-spec.json'), true)['reports'];
        $this->assertSame(array_column($spec, 'id'), $this->ids(), 'same reports, same order');
        $this->assertCount(41, $this->ids());
        foreach ($spec as $s) {
            $this->assertSame($s['domain'], app(ReportRegistry::class)->find($s['id'])->domain, $s['id']);
        }
    }

    public function test_every_report_runs_and_exports_to_csv_excel_and_pdf_with_the_rows_the_screen_shows(): void
    {
        $this->books();
        $this->adjustStock();
        $failures = [];
        $withRows = 0;
        foreach ($this->ids() as $id) {
            $q = array_filter($this->query($id), fn ($v) => $v !== null);
            $json = $this->getJson("/api/v1/reports/{$id}?".http_build_query($q));
            if ($json->getStatusCode() !== 200) {
                $failures[] = "{$id}: screen ".$json->getStatusCode();

                continue;
            }
            $data = $json->json('data');
            $total = $json->json('meta.total');
            $labels = array_column($data['columns'], 'label');
            $withRows += $total > 0 ? 1 : 0;

            $csv = $this->get("/api/v1/reports/{$id}/export?".http_build_query($q + ['format' => 'csv']));
            if ($csv->getStatusCode() !== 200 || ! str_contains((string) $csv->headers->get('Content-Type'), 'text/csv')) {
                $failures[] = "{$id}: csv ".$csv->getStatusCode();

                continue;
            }
            $lines = array_values(array_filter(array_map('str_getcsv', preg_split('/\R/', ltrim($csv->streamedContent(), "\xEF\xBB\xBF")) ?: []), fn ($r) => $r !== [null]));
            $expectedLines = 1 + $total + ($data['totals'] === [] ? 0 : 1);
            if ($lines[0] !== $labels || count($lines) !== $expectedLines) {
                $failures[] = "{$id}: csv has ".count($lines)." lines, expected {$expectedLines}";
            }

            $xlsx = $this->get("/api/v1/reports/{$id}/export?".http_build_query($q + ['format' => 'xlsx']));
            $rows = $xlsx->getStatusCode() === 200 ? $this->xlsxRows($xlsx->streamedContent()) : [];
            if (count($rows) !== $expectedLines || $rows[0] !== $labels) {
                $failures[] = "{$id}: xlsx has ".count($rows).' rows, expected '.$expectedLines;
            }

            $pdf = $this->get("/api/v1/reports/{$id}/export?".http_build_query($q + ['format' => 'pdf']));
            $bytes = $pdf->getStatusCode() === 200 ? $pdf->getContent() : '';
            if (! str_starts_with($bytes, '%PDF') || ! str_contains($this->pdfText($bytes), $data['name'])) {
                $failures[] = "{$id}: pdf is not a PDF titled {$data['name']}";
            }
        }
        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertGreaterThan(30, $withRows, 'the dataset should give most reports something to show');
    }

    public function test_csv_money_is_plain_decimals_and_text_that_looks_like_a_formula_is_defused(): void
    {
        $this->books();
        $csv = $this->get('/api/v1/reports/sales-summary/export?format=csv&from='.$this->today.'&to='.$this->today)->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('5200.00', $csv);
        $this->assertStringNotContainsString('৳', $csv);

        $this->customer(['name' => '=HYPERLINK("http://example.test")']);
        $this->actingAsRole('super_admin');
        $row = $this->get('/api/v1/reports/receivable-aging/export?format=csv')->streamedContent();
        $this->assertStringNotContainsString(',=HYPERLINK', $row);
    }

    public function test_the_excel_file_holds_numbers_not_text_and_a_totals_row(): void
    {
        $this->books();
        $rows = $this->xlsxRows($this->get('/api/v1/reports/sales-summary/export?format=xlsx&from='.$this->today.'&to='.$this->today)->streamedContent());
        $last = end($rows);
        $this->assertSame('Total', $last[0]);
        $this->assertEquals(5200.0, $last[array_search('Total', $rows[0], true)]);
        $this->assertIsInt($last[1]);
    }

    public function test_every_export_leaves_an_audit_row_and_a_pdf_can_be_viewed_inline(): void
    {
        $this->books();
        $this->get('/api/v1/reports/sales-summary/export?format=csv')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'export', 'entity_table' => 'reports']);
        $inline = $this->get('/api/v1/reports/sales-summary/export?format=pdf&inline=1');
        $this->assertStringStartsWith('inline;', (string) $inline->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Orbin_sales-summary_', (string) $inline->headers->get('Content-Disposition'));
    }

    public function test_a_long_report_exports_in_slices_without_losing_a_row(): void
    {
        $this->books();
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = ['created_at' => now(), 'branch_id' => 1, 'party_type' => 'customer', 'party_id' => $this->customer(['name' => 'Bulk '.$i])->id, 'entry_date' => $this->today, 'entry_type' => 'adjustment', 'debit' => '10.00', 'credit' => '0.00', 'description' => 'bulk'];
        }
        \DB::table('party_ledger_entries')->insert($rows);
        $this->actingAsRole('super_admin');
        $pdf = $this->get('/api/v1/reports/receivable-aging/export?format=pdf')->getContent();
        $text = $this->pdfText($pdf);
        $this->assertSame(400, substr_count($text, 'Bulk '), 'one line per customer, none lost where the slices join');
        $csv = $this->get('/api/v1/reports/receivable-aging/export?format=csv')->streamedContent();
        $this->assertSame(400, substr_count($csv, 'Bulk '));
    }
}
