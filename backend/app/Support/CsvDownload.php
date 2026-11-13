<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Small CSV exports (stock card, movements, valuation, aging) through openspout, so the output is Excel-safe UTF-8 with a BOM. */
final class CsvDownload
{
    /** @param  array<int, string>  $headers  @param  iterable<int, array<int, scalar|null>>  $rows */
    public static function make(string $filename, array $headers, iterable $rows): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'orbin-csv');
        $writer = new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues($r));
        }
        $writer->close();

        return response()->download($path, $filename, ['Content-Type' => 'text/csv; charset=UTF-8'])->deleteFileAfterSend(true);
    }
}
