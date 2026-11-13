<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * One place that builds an mPDF with the brand fonts (Inter body, Plus Jakarta Sans headings, Hind Siliguri for Bangla),
 * OpenType shaping on, and fonts subset so a PDF stays small (spec invoiceSpec.engine, NFR-020).
 */
class PdfFactory
{
    /** @param  array{format?: string|array<int, int|float>, margin?: array<int, int|float>}  $opts */
    public static function make(string $title, array $opts = []): Mpdf
    {
        $tmp = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($tmp);
        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();
        $margin = $opts['margin'] ?? [14, 14, 14, 18];
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'tempDir' => $tmp, 'format' => $opts['format'] ?? 'A4',
            'margin_left' => $margin[0], 'margin_right' => $margin[1], 'margin_top' => $margin[2], 'margin_bottom' => $margin[3], 'margin_header' => 0, 'margin_footer' => 6,
            'fontDir' => array_merge($defaults['fontDir'], [resource_path('fonts')]),
            'fontdata' => $fonts['fontdata'] + [
                'inter' => ['R' => 'Inter_400Regular.ttf', 'B' => 'Inter_700Bold.ttf', 'I' => 'Inter_400Regular_Italic.ttf', 'BI' => 'Inter_400Regular_Italic.ttf'],
                'jakarta' => ['R' => 'PlusJakartaSans_400Regular.ttf', 'B' => 'PlusJakartaSans_800ExtraBold.ttf'],
                'bangla' => ['R' => 'HindSiliguri_400Regular.ttf', 'B' => 'HindSiliguri_700Bold.ttf', 'useOTL' => 0xFF],
            ],
            'default_font' => 'inter', 'default_font_size' => 9, 'useSubstitutions' => true, 'autoScriptToLang' => false,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('Orbin POS');
        $mpdf->SetAuthor('Orbin Global');
        $mpdf->SetCompression((bool) config('orbin.pdf_compress', true));
        $mpdf->shrink_tables_to_fit = 1;

        return $mpdf;
    }
}
