<?php

namespace App\Services\Catalog;

use App\Models\ProductVariant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/** Streams the catalog as CSV/XLSX in the same column layout the importer reads (round-trippable). Cost is never exported. */
class ExportService
{
    private const CHUNK = 500;

    public function stream(string $format, ?string $search = null): void
    {
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile('php://output');
        $writer->addRow(Row::fromValues(ImportService::HEADERS['products']));
        $query = ProductVariant::query()->with(['product.category', 'product.brand', 'product.qualityGrade', 'product.originCountry', 'product.unit', 'product.taxRate', 'glassType', 'tint', 'colorFinish', 'crossReferences'])->orderBy('product_id')->orderBy('id');
        if ($search) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where('search_text', 'like', $like);
        }
        $query->chunkById(self::CHUNK, function ($chunk) use ($writer): void {
            foreach ($chunk as $v) {
                /** @var ProductVariant $v */
                $p = $v->product;
                $yes = fn (bool $b) => $b ? 'yes' : '';
                $writer->addRow(Row::fromValues([
                    $p->code, $p->name_en, $p->name_bn, $p->mirror_type, $p->category?->name_en, $p->brand?->name, $p->qualityGrade?->label_en, $p->originCountry?->label_en,
                    $p->unit?->code, $p->taxRate?->code, $p->warranty_months, implode(';', $p->tags ?? []),
                    $v->sku, $v->barcode, $v->position, $v->size_width_mm, $v->size_height_mm, $v->thickness_mm, $v->size_label, $v->glassType?->label_en, $v->tint?->label_en,
                    $v->colorFinish?->label_en, $v->adjustment, $yes($v->heated), $yes($v->auto_fold), $yes($v->turn_signal), $yes($v->blind_spot_sensor), $yes($v->side_camera),
                    $yes($v->memory_tilt), $yes($v->auto_dimming), $yes($v->puddle_light), $v->pin_count, $v->retail_price, $v->wholesale_price, $v->dealer_price, $v->mrp,
                    $v->min_price, $v->reorder_level, $v->reorder_qty, $v->crossReferences->where('ref_type', 'oem')->pluck('number')->implode(';'),
                ]));
            }
        });
        $writer->close();
    }
}
