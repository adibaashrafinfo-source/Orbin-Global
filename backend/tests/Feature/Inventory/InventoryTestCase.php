<?php

namespace Tests\Feature\Inventory;

use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\StockPostingService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\CatalogTestCase;

/** Helpers shared by inventory and purchasing tests. */
abstract class InventoryTestCase extends CatalogTestCase
{
    protected int $showroom;

    protected int $godown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->showroom = (int) Warehouse::query()->where('kind', 'showroom')->value('id');
        $this->godown = (int) Warehouse::query()->where('kind', 'godown')->value('id');
    }

    /** One product with $n variants (different sizes); returns their ids. @return array<int, int> */
    protected function variants(int $n = 1, array $variantOver = []): array
    {
        $this->actingAsRole('super_admin');
        $product = $this->makeProduct(['code' => 'INV-'.substr(md5((string) microtime(true).random_int(0, 99999)), 0, 6)]);
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = (int) $this->makeVariant($product, array_merge(['size_width_mm' => 100 + $i, 'size_height_mm' => 50 + $i, 'retail_price' => '1000.00'], $variantOver))['id'];
        }

        return $ids;
    }

    protected function level(int $variant, ?int $warehouse = null): ?StockLevel
    {
        return StockLevel::query()->where(['variant_id' => $variant, 'warehouse_id' => $warehouse ?? $this->showroom])->first();
    }

    protected function onHand(int $variant, ?int $warehouse = null): string
    {
        return (string) ($this->level($variant, $warehouse)?->qty_on_hand ?? '0.000');
    }

    protected function avgCost(int $variant, ?int $warehouse = null): string
    {
        return (string) ($this->level($variant, $warehouse)?->avg_cost ?? '0.0000');
    }

    /** @return array<int, StockMovement> */
    protected function movements(int $variant, ?int $warehouse = null): array
    {
        return StockMovement::query()->where('variant_id', $variant)->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse))->orderBy('id')->get()->all();
    }

    /** Put stock on the shelf the way go-live does: an opening movement. */
    protected function stock(int $variant, string $qty, string $cost = '100', ?int $warehouse = null): void
    {
        DB::transaction(fn () => app(StockPostingService::class)->post(['variant_id' => $variant, 'warehouse_id' => $warehouse ?? $this->showroom, 'type' => 'opening', 'qty' => $qty, 'unit_cost' => $cost]));
    }

    protected function reasonId(string $code): int
    {
        return $this->lookup('adjustment_reason', $code);
    }
}
