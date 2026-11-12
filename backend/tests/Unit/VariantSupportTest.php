<?php

namespace Tests\Unit;

use App\Models\OemCrossReference;
use App\Models\VehicleFitment;
use App\Services\Catalog\VariantSupport;
use PHPUnit\Framework\TestCase;

class VariantSupportTest extends TestCase
{
    public function test_size_label_is_generated_from_dimensions_unless_overridden(): void
    {
        $this->assertSame('180 × 110 mm', VariantSupport::sizeLabel(180, 110, ''));
        $this->assertSame('180.5 × 110 mm', VariantSupport::sizeLabel('180.5', '110.0', null));
        $this->assertSame('7 × 4 in', VariantSupport::sizeLabel(180, 110, '7 × 4 in'));
        $this->assertNull(VariantSupport::sizeLabel(180, null, ''));
        $this->assertNull(VariantSupport::sizeLabel(null, null, ' '));
    }

    public function test_signature_is_stable_and_changes_with_every_identity_field(): void
    {
        $base = ['product_id' => 1, 'position' => 'right', 'size_label' => '180 × 110 mm', 'glass_type_id' => 3, 'tint_id' => null, 'color_finish_id' => 5, 'adjustment' => 'electric', 'heated' => true];
        $sig = VariantSupport::signature($base);
        $this->assertSame($sig, VariantSupport::signature($base));
        $this->assertSame($sig, VariantSupport::signature($base + ['auto_fold' => false, 'unrelated' => 'x']), 'false flags and unrelated keys do not matter');
        $this->assertSame($sig, VariantSupport::signature(array_merge($base, ['size_label' => ' 180 × 110 MM '])), 'size label is case and space insensitive');
        $changes = ['product_id' => 2, 'position' => 'left', 'size_label' => '200 × 120 mm', 'glass_type_id' => 4, 'tint_id' => 9, 'color_finish_id' => 6, 'adjustment' => 'manual', 'heated' => false, 'auto_fold' => true, 'blind_spot_sensor' => true, 'is_glass_only' => true, 'cover_included' => true, 'pin_count' => 5];
        foreach ($changes as $field => $value) {
            $this->assertNotSame($sig, VariantSupport::signature(array_merge($base, [$field => $value])), "{$field} must be part of the signature");
        }
        // price, barcode and stock settings are NOT identity
        $this->assertSame($sig, VariantSupport::signature(array_merge($base, ['retail_price' => 1, 'barcode' => 'x', 'reorder_level' => 5, 'sku' => 'A'])));
    }

    public function test_variant_name_and_sku_base(): void
    {
        $a = ['position' => 'left', 'adjustment' => 'manual', 'heated' => true, 'blind_spot_sensor' => true, 'size_label' => '7 × 4 in'];
        $this->assertSame('LH · Manual · Heated · BSM · Flat · Silver · 7 × 4 in', VariantSupport::variantName($a, 'Flat', null, 'Silver'));
        $this->assertSame('Pair (LH+RH)', VariantSupport::variantName(['position' => 'pair'], null, null, null));
        $this->assertSame('Interior · Glass only', VariantSupport::variantName(['position' => 'interior', 'is_glass_only' => true], null, null, null));
        $this->assertSame('SM-TOY-AXIO-R-180X110', VariantSupport::skuBase('sm-toy-axio', 'right', null, 180.0, 110.0));
        $this->assertSame('X-INT-UNIVERSAL7X4', VariantSupport::skuBase('x', 'interior', 'Universal 7x4', null, null));
        $this->assertSame('X-UNI', VariantSupport::skuBase('x', 'universal', null, null, null));
    }

    public function test_ean13_check_digits_match_published_examples(): void
    {
        $this->assertSame(1, VariantSupport::ean13CheckDigit('400638133393')); // 4006381333931
        $this->assertSame(7, VariantSupport::ean13CheckDigit('590123412345')); // 5901234123457
        $this->assertTrue(VariantSupport::isValidEan13('4006381333931'));
        $this->assertTrue(VariantSupport::isValidEan13('5901234123457'));
        $this->assertFalse(VariantSupport::isValidEan13('4006381333932'));
        $this->assertFalse(VariantSupport::isValidEan13('12345'));
        $this->assertFalse(VariantSupport::isValidEan13('40063813339ab'));
        $code = VariantSupport::ean13ForVariant(42);
        $this->assertSame(13, strlen($code));
        $this->assertStringStartsWith('200000000042', $code);
        $this->assertTrue(VariantSupport::isValidEan13($code));
        $this->assertNotSame(VariantSupport::ean13ForVariant(43), $code);
    }

    public function test_oem_numbers_normalize_regardless_of_spelling(): void
    {
        foreach (['87910-02B30', '87910 02b30', '8791002B30', ' 87910.02B30 ', '87910/02-b30'] as $raw) {
            $this->assertSame('8791002B30', OemCrossReference::normalize($raw), $raw);
        }
    }

    public function test_fitment_signature_treats_missing_years_and_trims_consistently(): void
    {
        $a = VehicleFitment::signatureFor(1, 2, 2012, null, null);
        $this->assertSame($a, VehicleFitment::signatureFor(1, 2, 2012, null, '  '));
        $this->assertNotSame($a, VehicleFitment::signatureFor(1, 2, 2012, 2018, null));
        $this->assertNotSame($a, VehicleFitment::signatureFor(1, 2, 2012, null, 'Hybrid'));
        $this->assertSame(VehicleFitment::signatureFor(1, 2, 2012, null, 'Hybrid'), VehicleFitment::signatureFor(1, 2, 2012, null, ' hybrid '));
        $this->assertNotSame($a, VehicleFitment::signatureFor(2, 2, 2012, null, null));
    }
}
