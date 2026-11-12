<?php

namespace Tests\Feature\Catalog;

use App\Models\ProductVariant;
use App\Services\Catalog\VariantSupport;
use Symfony\Component\Process\Process;

/** Label PDFs are checked with poppler (pdfinfo / pdftotext), the same tools a reviewer would use. */
class LabelTest extends CatalogTestCase
{
    private function pdf(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lbl').'.pdf';
        file_put_contents($path, $content);

        return $path;
    }

    /** @return array{pages: int, size: string} */
    private function info(string $path): array
    {
        $p = new Process(['pdfinfo', $path]);
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'pdfinfo must be installed to verify labels: '.$p->getErrorOutput());
        preg_match('/Pages:\s+(\d+)/', $p->getOutput(), $pages);
        preg_match('/Page size:\s+([\d.]+) x ([\d.]+) pts/', $p->getOutput(), $size);

        return ['pages' => (int) $pages[1], 'size' => round((float) $size[1], 1).'x'.round((float) $size[2], 1)];
    }

    private function text(string $path): string
    {
        $p = new Process(['pdftotext', '-layout', $path, '-']);
        $p->run();
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return $p->getOutput();
    }

    private function twoVariants(): array
    {
        $pid = $this->makeProduct();
        $a = $this->makeVariant($pid, ['barcode' => VariantSupport::ean13ForVariant(900001)]);
        $b = $this->makeVariant($pid, ['position' => 'left', 'retail_price' => '9800.50']);

        return [$a, $b];
    }

    public function test_roll_labels_are_50_by_25_mm_one_per_page_with_sku_size_and_price(): void
    {
        $this->actingAsRole('manager');
        [$a, $b] = $this->twoVariants();
        $res = $this->postJson('/api/v1/labels', ['template' => 'roll-50x25', 'items' => [['variant_id' => $a['id'], 'qty' => 2], ['variant_id' => $b['id'], 'qty' => 1]]])->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $path = $this->pdf($res->getContent());
        $info = $this->info($path);
        $this->assertSame(3, $info['pages']);
        $this->assertSame('141.7x70.9', $info['size'], '50 × 25 mm is 141.7 × 70.9 pt');
        $text = $this->text($path);
        $this->assertStringContainsString('SM-TOY-AXIO-R-180X110', $text);
        $this->assertStringContainsString('SM-TOY-AXIO-L-180X110', $text);
        $this->assertStringContainsString('180 × 110 mm RH', $text);
        $this->assertStringContainsString('Tk 12,500.00', $text);
        $this->assertStringContainsString('Tk 9,800.50', $text);
        $this->assertStringContainsString(VariantSupport::ean13ForVariant(900001), preg_replace('/\s+/', '', $text), 'the barcode digits are printed');
    }

    public function test_a4_sheet_holds_27_labels_per_page_and_start_position_skips_used_labels(): void
    {
        $this->actingAsRole('manager');
        [$a] = $this->twoVariants();
        $item = fn (int $qty) => [['variant_id' => $a['id'], 'qty' => $qty]];
        $count = fn (array $body) => $this->info($this->pdf($this->postJson('/api/v1/labels', $body)->assertOk()->getContent()));

        $one = $count(['template' => 'a4', 'items' => $item(27)]);
        $this->assertSame(1, $one['pages']);
        $this->assertSame('595.3x841.9', $one['size'], 'A4');
        $this->assertSame(2, $count(['template' => 'a4', 'items' => $item(28)])['pages']);
        $this->assertSame(1, $count(['template' => 'a4', 'items' => $item(1)])['pages']);
        $this->assertSame(2, $count(['template' => 'a4', 'items' => $item(25), 'start_position' => 5])['pages']); // 4 used labels + 25 = 29 slots
        $this->assertSame(1, $count(['template' => 'a4', 'items' => $item(22), 'start_position' => 5])['pages']);

        $text = $this->text($this->pdf($this->postJson('/api/v1/labels', ['template' => 'a4', 'items' => $item(3), 'show_price' => false])->getContent()));
        $this->assertSame(3, substr_count($text, 'SM-TOY-AXIO-R-180X110'));
        $this->assertStringNotContainsString('Tk 12,500.00', $text);
    }

    public function test_missing_barcodes_are_generated_or_refused(): void
    {
        $this->actingAsRole('manager');
        [, $b] = $this->twoVariants();
        $this->assertNull(ProductVariant::query()->find($b['id'])->barcode);
        $this->postJson('/api/v1/labels', ['template' => 'roll-50x25', 'generate_missing' => false, 'items' => [['variant_id' => $b['id'], 'qty' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'LABEL_NO_BARCODE')->assertJsonPath('context.sku', 'SM-TOY-AXIO-L-180X110');
        $this->assertNull(ProductVariant::query()->find($b['id'])->barcode);
        $this->postJson('/api/v1/labels', ['template' => 'roll-50x25', 'items' => [['variant_id' => $b['id'], 'qty' => 1]]])->assertOk();
        $code = ProductVariant::query()->find($b['id'])->barcode;
        $this->assertTrue(VariantSupport::isValidEan13($code));
    }

    public function test_label_input_guards_and_storekeeper_may_print(): void
    {
        $this->actingAsRole('manager');
        [$a] = $this->twoVariants();
        $this->postJson('/api/v1/labels', ['template' => 'poster', 'items' => [['variant_id' => $a['id'], 'qty' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/labels', ['template' => 'a4', 'items' => []])->assertStatus(422);
        $this->postJson('/api/v1/labels', ['template' => 'a4', 'items' => [['variant_id' => 999999, 'qty' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/labels', ['template' => 'a4', 'items' => [['variant_id' => $a['id'], 'qty' => 501]]])->assertStatus(422);
        $this->postJson('/api/v1/labels', ['template' => 'a4', 'items' => array_fill(0, 4, ['variant_id' => $a['id'], 'qty' => 400])])->assertStatus(422)->assertJsonPath('code', 'LABEL_LIMIT');
        $this->actingAsRole('storekeeper'); // storekeepers print labels (PRD personas)
        $this->postJson('/api/v1/labels', ['template' => 'roll-50x25', 'items' => [['variant_id' => $a['id'], 'qty' => 1]]])->assertOk();
    }
}
