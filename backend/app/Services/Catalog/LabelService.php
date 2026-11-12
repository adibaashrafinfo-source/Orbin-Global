<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/**
 * Barcode label PDFs (FR-305, NFR-100) rendered server-side with mPDF: an A4 sheet (3 × 9 labels of 70 × 33 mm) or a roll of
 * 50 × 25 mm labels (one label per page). Each label has the barcode, SKU, short name, size and price.
 */
class LabelService
{
    public const TEMPLATES = ['a4', 'roll-50x25'];

    public const MAX_LABELS = 1500;

    public function __construct(private readonly VariantService $variants) {}

    /**
     * @param  array<int, array{variant_id: int, qty: int}>  $items
     * @param  array{template: string, show_price?: bool, show_name?: bool, show_size?: bool, generate_missing?: bool, start_position?: int}  $opts
     */
    public function render(array $items, array $opts): string
    {
        $template = $opts['template'];
        $labels = $this->expand($items, (bool) ($opts['generate_missing'] ?? true));
        $start = $template === 'a4' ? max(1, min(27, (int) ($opts['start_position'] ?? 1))) : 1;
        $flags = ['price' => (bool) ($opts['show_price'] ?? true), 'name' => (bool) ($opts['show_name'] ?? true), 'size' => (bool) ($opts['show_size'] ?? true)];

        $tmp = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($tmp);
        $roll = $template === 'roll-50x25';
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'tempDir' => $tmp, 'default_font' => 'dejavusans', 'default_font_size' => 7,
            'format' => $roll ? [50, 25] : 'A4', 'margin_left' => $roll ? 1.5 : 0, 'margin_right' => $roll ? 1.5 : 0,
            'margin_top' => $roll ? 1.2 : 0, 'margin_bottom' => $roll ? 0.5 : 0, 'margin_header' => 0, 'margin_footer' => 0,
            'useSubstitutions' => false,
        ]);
        $mpdf->SetAutoPageBreak(false); // we place exactly one label per roll page and 27 per sheet ourselves
        $mpdf->SetTitle('Orbin labels');
        $mpdf->SetCreator('Orbin POS');
        $mpdf->SetCompression(config('orbin.pdf_compress', true));
        $mpdf->WriteHTML($roll ? $this->rollHtml($labels, $flags) : $this->sheetHtml($labels, $flags, $start));

        return $mpdf->Output('', 'S');
    }

    /** @return array<int, array<string, string>> one entry per physical label */
    private function expand(array $items, bool $generateMissing): array
    {
        $total = array_sum(array_map(fn ($i) => (int) $i['qty'], $items));
        if ($total < 1) {
            throw new ApiException('VALIDATION', 422, '', ['items' => ['errors.REQUIRED']]);
        }
        if ($total > self::MAX_LABELS) {
            throw new ApiException('LABEL_LIMIT', 422, '', [], ['max' => self::MAX_LABELS, 'requested' => $total]);
        }
        $labels = [];
        foreach ($items as $item) {
            $variant = ProductVariant::query()->with(['product'])->find($item['variant_id']);
            if (! $variant) {
                throw ApiException::notFound();
            }
            if ($variant->barcode === null) {
                if (! $generateMissing) {
                    throw new ApiException('LABEL_NO_BARCODE', 422, '', [], ['sku' => $variant->sku]);
                }
                $variant = $this->variants->generateBarcode($variant);
            }
            $data = [
                'barcode' => (string) $variant->barcode, 'sku' => $variant->sku, 'name' => $variant->product->name_en,
                'size' => trim(($variant->size_label ?? '').' '.$this->positionShort($variant->position)),
                'price' => 'Tk '.Money::of((string) $variant->retail_price)->format(false),
            ];
            for ($i = 0; $i < (int) $item['qty']; $i++) {
                $labels[] = $data;
            }
        }

        return $labels;
    }

    private function positionShort(string $p): string
    {
        return ['left' => 'LH', 'right' => 'RH', 'interior' => 'INT', 'pair' => 'PAIR', 'universal' => 'UNIV'][$p] ?? '';
    }

    /**
     * One label. mPDF barcode height 1.0 is about 15.8 mm, so the bar height is chosen per label size; the human-readable digits are
     * printed by the barcode itself.
     *
     * @param  array<string, string>  $l
     */
    private function cell(array $l, array $flags, float $w, float $h): string
    {
        $ean = VariantSupport::isValidEan13($l['barcode']);
        $sheet = $w >= 60;
        $barcode = '<barcode code="'.htmlspecialchars($l['barcode']).'" type="'.($ean ? 'EAN13' : 'C128B').'" size="'.($sheet ? '1.0' : '0.82').'" height="'.($sheet ? '0.72' : '0.40').'" />';
        $name = $flags['name'] ? '<div class="nm">'.htmlspecialchars(mb_strimwidth($l['name'], 0, $sheet ? 38 : 30, '…')).'</div>' : '';
        $line = '<div class="ln"'.($sheet ? '' : ' style="font-size:5pt"').'>'.htmlspecialchars($l['sku']).($flags['size'] && $l['size'] !== '' ? ' · '.htmlspecialchars($l['size']) : '').'</div>';
        $price = $flags['price'] ? '<div class="pr">'.htmlspecialchars($l['price']).'</div>' : '';

        return '<div style="width:'.($w - 4).'mm;text-align:center;">'.$name.'<div class="bc">'.$barcode.'</div>'.$line.$price.'</div>';
    }

    private const CSS = '<style>body{font-family:dejavusans;} .nm{font-size:6.5pt;font-weight:bold;text-align:center;} .bc{text-align:center;padding-top:0.4mm;} .ln{font-size:5.6pt;text-align:center;} .pr{font-size:8pt;font-weight:bold;text-align:center;} table.sheet{border-collapse:collapse;width:210mm;} table.sheet td{width:70mm;height:33mm;vertical-align:middle;overflow:hidden;}</style>';

    /** @param array<int, array<string, string>> $labels */
    private function sheetHtml(array $labels, array $flags, int $start): string
    {
        $slots = array_merge(array_fill(0, $start - 1, null), $labels);
        $perPage = 27;
        $html = self::CSS;
        foreach (array_chunk($slots, $perPage) as $pageNo => $page) {
            $page = array_pad($page, $perPage, null);
            $html .= $pageNo > 0 ? '<pagebreak />' : '';
            $html .= '<table class="sheet" autosize="1">';
            foreach (array_chunk($page, 3) as $row) {
                $html .= '<tr>';
                foreach ($row as $l) {
                    $html .= '<td align="center">'.($l ? $this->cell($l, $flags, 70, 33) : '').'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        return $html;
    }

    /** @param array<int, array<string, string>> $labels */
    private function rollHtml(array $labels, array $flags): string
    {
        $html = self::CSS;
        foreach ($labels as $i => $l) {
            $html .= ($i > 0 ? '<pagebreak />' : '').'<div style="width:47mm;height:22.5mm;overflow:hidden;">'.$this->cell($l, $flags, 47, 23).'</div>';
        }

        return $html;
    }
}
