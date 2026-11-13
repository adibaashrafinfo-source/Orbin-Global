<?php

namespace Tests\Feature\Inventory;

use App\Models\ProductVariant;

class ProductStockTest extends InventoryTestCase
{
    public function test_products_and_variants_report_stock_and_the_list_filters_by_it(): void
    {
        [$a, $b] = $this->variants(2);
        $this->stock($a, '4', '10');
        $this->stock($a, '3', '10', $this->godown);
        $productId = (int) ProductVariant::query()->find($a)->product_id;

        $list = $this->getJson('/api/v1/products?search=INV-')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $productId);
        $this->assertSame('7.000', $row['stock_total']);
        $detail = $this->getJson("/api/v1/products/{$productId}")->assertOk()->json('data');
        $byId = collect($detail['variants'])->keyBy('id');
        $this->assertSame(['7.000', '0.000'], [$byId[$a]['stock_on_hand'], $byId[$b]['stock_on_hand']]);
        $this->getJson("/api/v1/variants/{$a}")->assertOk()->assertJsonPath('data.stock_on_hand', '7.000');

        $this->assertContains($productId, collect($this->getJson('/api/v1/products?filter[stock]=in&per_page=100')->json('data'))->pluck('id')->all());
        $this->assertNotContains($productId, collect($this->getJson('/api/v1/products?filter[stock]=out&per_page=100')->json('data'))->pluck('id')->all());
    }

    public function test_deactivating_a_product_with_stock_needs_confirmation(): void
    {
        [$a] = $this->variants();
        $productId = (int) ProductVariant::query()->find($a)->product_id;
        $this->postJson("/api/v1/products/{$productId}/publish")->assertOk();
        $this->stock($a, '5', '10');
        $this->postJson("/api/v1/products/{$productId}/deactivate")->assertStatus(409)->assertJsonPath('code', 'HAS_STOCK')->assertJsonPath('context.units', '5.000');
        $this->getJson("/api/v1/products/{$productId}")->assertJsonPath('data.is_active', true);
        $this->postJson("/api/v1/products/{$productId}/deactivate", ['confirm' => true])->assertOk()->assertJsonPath('data.is_active', false);
        // No stock, no question.
        [$b] = $this->variants();
        $other = (int) ProductVariant::query()->find($b)->product_id;
        $this->postJson("/api/v1/products/{$other}/publish")->assertOk();
        $this->postJson("/api/v1/products/{$other}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
    }
}
