<?php

namespace Tests\Feature\Sales;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantBundleItem;
use App\Models\VehicleModel;
use App\Services\Catalog\VariantService;
use App\Services\Sales\VariantSearchService;
use Illuminate\Support\Facades\DB;

/** FR-702 to FR-704: the vehicle finder, text search, scanning and live stock on size chips. */
class PosSearchTest extends SalesTestCase
{
    private function search(array $f = []): array
    {
        return app(VariantSearchService::class)->search($f + ['warehouse_id' => $this->showroom], 24, 0);
    }

    /** @return array<int, int> variant ids of every card's chips */
    private function ids(array $result): array
    {
        return collect($result['data'])->flatMap(fn ($c) => collect($c['variants'])->pluck('id'))->sort()->values()->all();
    }

    private function fit(int $variant, string $model, int $from, ?int $to): void
    {
        app(VariantService::class)->addFitmentRow($variant, (int) VehicleModel::query()->where('name', $model)->value('id'), $from, $to, null, null);
    }

    public function test_the_vehicle_finder_returns_only_fitting_variants_for_make_model_year_and_side(): void
    {
        [$right, $left, $pair, $other] = $this->stocked(4, '5');
        DB::table('product_variants')->where('id', $left)->update(['position' => 'left']);
        DB::table('product_variants')->where('id', $pair)->update(['position' => 'pair']);
        $this->fit($right, 'Axio', 2012, 2018);
        $this->fit($left, 'Axio', 2012, 2018);
        $this->fit($pair, 'Axio', 2010, null);
        $this->fit($other, 'Allion', 2012, 2018);
        $axio = (int) VehicleModel::query()->where('name', 'Axio')->value('id');
        $toyota = (int) VehicleModel::query()->where('name', 'Axio')->value('make_id');

        $this->assertSame([$right, $left, $pair], $this->ids($this->search(['model_id' => $axio, 'year' => 2015])));
        $this->assertSame([$right, $pair], $this->ids($this->search(['model_id' => $axio, 'year' => 2015, 'position' => 'right'])), 'a pair fits either side, the left mirror does not');
        $this->assertSame([$pair], $this->ids($this->search(['model_id' => $axio, 'year' => 2020])), 'open-ended years still fit; closed ones do not');
        $this->assertSame([$right, $left, $pair, $other], $this->ids($this->search(['make_id' => $toyota])));
        $this->assertSame([], $this->ids($this->search(['model_id' => $axio, 'year' => 2005])));
    }

    public function test_in_stock_cards_come_first_and_only_in_stock_filters_the_rest(): void
    {
        $a = $this->variants(1)[0];
        $b = $this->stocked(1, '4')[0];
        Product::query()->whereKey(ProductVariant::query()->find($a)->product_id)->update(['is_active' => true]);
        $this->assertSame([ProductVariant::query()->find($b)->product_id, ProductVariant::query()->find($a)->product_id], array_column($this->search()['data'], 'product_id'));
        $this->assertSame([$b], $this->ids($this->search(['only_in_stock' => true])));
        $chip = $this->search()['data'][0]['variants'][0];
        $this->assertSame(['4.000'], [$chip['available']]);
    }

    public function test_text_search_matches_sku_name_and_oem_number_in_any_spelling(): void
    {
        [$a, $b] = $this->stocked(2, '4');
        DB::table('oem_cross_references')->insert(['variant_id' => $a, 'ref_type' => 'oem', 'number' => '87910-12B50', 'number_normalized' => '8791012B50', 'created_at' => now(), 'updated_at' => now()]);
        $sku = ProductVariant::query()->find($b)->sku;
        $this->assertSame([$a], $this->ids($this->search(['text' => '8791012b50'])));
        $this->assertSame([$a], $this->ids($this->search(['text' => '87910 12b50'])));
        $this->assertSame([$b], $this->ids($this->search(['text' => strtolower($sku)])));
    }

    public function test_scanning_a_barcode_or_sku_finds_exactly_one_variant_and_unknown_codes_find_none(): void
    {
        [$a] = $this->stocked(1, '4');
        DB::table('product_variants')->where('id', $a)->update(['barcode' => '2000000000017']);
        $svc = app(VariantSearchService::class);
        $hit = $svc->scan('2000000000017', $this->showroom);
        $this->assertSame([$a, '4.000'], [$hit['variant']['id'], $hit['variant']['available']]);
        $this->assertSame($a, $svc->scan(ProductVariant::query()->find($a)->sku, $this->showroom)['variant']['id']);
        $this->assertNull($svc->scan('9999999999999', $this->showroom));
    }

    public function test_a_bundle_shows_as_many_units_as_its_scarcest_component_allows(): void
    {
        [$bundle, $glass, $cap] = $this->stocked(3, '10');
        DB::table('stock_levels')->where('variant_id', $cap)->update(['qty_on_hand' => '5.000']);
        ProductVariant::query()->whereKey($bundle)->update(['is_bundle' => true]);
        VariantBundleItem::query()->create(['bundle_variant_id' => $bundle, 'component_variant_id' => $glass, 'qty' => '1']);
        VariantBundleItem::query()->create(['bundle_variant_id' => $bundle, 'component_variant_id' => $cap, 'qty' => '2']);
        $chip = collect($this->search(['text' => ProductVariant::query()->find($bundle)->sku])['data'])->flatMap(fn ($c) => $c['variants'])->firstWhere('id', $bundle);
        $this->assertSame(['2.000', true], [$chip['available'], $chip['is_bundle']], '5 caps ÷ 2 per bundle = 2 bundles');
    }

    public function test_each_chip_carries_the_tax_rate_the_server_will_apply(): void
    {
        [$plain, $own] = $this->stocked(2, '4');
        DB::table('tax_rates')->where('code', 'VAT0')->update(['is_default' => true]);
        DB::table('product_variants')->where('id', $own)->update(['tax_rate_id' => DB::table('tax_rates')->where('code', 'VAT15')->value('id')]);
        $chips = collect($this->search()['data'])->flatMap(fn ($c) => $c['variants'])->keyBy('id');
        $this->assertSame(['0.00', '15.00'], [$chips[$plain]['tax_rate'], $chips[$own]['tax_rate']], 'a variant override beats the shop default');
    }

    public function test_no_cost_or_internal_field_reaches_the_pos_response(): void
    {
        $this->stocked(1, '4');
        $json = json_encode($this->search());
        foreach (['last_cost', 'avg_cost', 'unit_cost', 'internal_note', 'supplier_part_no'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $json);
        }
    }
}
