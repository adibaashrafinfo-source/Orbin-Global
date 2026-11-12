<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Services\AuditService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Bulk price update (FR-304): percentage or fixed change on a filtered set, with a preview and one audit entry per changed price. */
class PriceService
{
    public const COLUMNS = ['retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price'];

    public function __construct(private readonly AuditService $audit, private readonly VariantService $variants) {}

    /**
     * @param  array<string, mixed>  $spec  {mode: percent|fixed, amount, columns[], filter{product_ids,category_id,brand_id,mirror_type,variant_ids}}
     * @return array{count: int, changed: int, sample: array<int, array<string, mixed>>}
     */
    public function run(array $spec, bool $dryRun): array
    {
        $this->assertSpec($spec);
        $columns = $spec['columns'];
        $mode = $spec['mode'];
        $amount = (string) $spec['amount'];
        $query = $this->variantQuery($spec['filter'] ?? []);
        $count = (clone $query)->count();
        if ($count === 0) {
            throw new ApiException('NO_MATCH', 422);
        }
        $sample = [];
        $changed = 0;
        $apply = function () use ($query, $columns, $mode, $amount, $dryRun, &$sample, &$changed): void {
            $query->orderBy('id')->chunkById(200, function ($chunk) use ($columns, $mode, $amount, $dryRun, &$sample, &$changed): void {
                foreach ($chunk as $v) {
                    /** @var ProductVariant $v */
                    $new = [];
                    foreach ($columns as $col) {
                        $old = $v->{$col};
                        if ($old === null) {
                            continue;
                        }
                        $next = $this->compute((string) $old, $mode, $amount);
                        if ($next->toDecimal() !== Money::of((string) $old)->toDecimal()) {
                            $new[$col] = $next->toDecimal();
                        }
                    }
                    if ($new === []) {
                        continue;
                    }
                    $changed++;
                    if (count($sample) < 8) {
                        $sample[] = ['variant_id' => $v->id, 'sku' => $v->sku, 'before' => array_intersect_key($v->only($columns), $new), 'after' => $new];
                    }
                    if (! $dryRun) {
                        $before = array_intersect_key($v->only($columns), $new);
                        // keep the floor price at or below retail price
                        $retail = $new['retail_price'] ?? $v->retail_price;
                        $floor = $new['min_price'] ?? $v->min_price;
                        if ($floor !== null && (float) $floor > (float) $retail) {
                            $new['min_price'] = $retail;
                        }
                        $v->forceFill($new)->saveQuietly();
                        $this->variants->refreshDerived($v);
                        $this->audit->log('price_change', 'product_variants', $v->id, $before, $new, 'bulk price update '.$mode.' '.$amount);
                    }
                }
            });
        };
        $dryRun ? $apply() : DB::transaction($apply);

        return ['count' => $count, 'changed' => $changed, 'sample' => $sample];
    }

    private function compute(string $old, string $mode, string $amount): Money
    {
        $money = Money::of($old);
        $delta = $mode === 'percent' ? $this->percentDelta($money, $amount) : Money::of(ltrim($amount, '+'));
        $result = $money->add($delta);
        if ($result->isNegative()) {
            throw new ApiException('PRICE_NEGATIVE', 422); // BR-094
        }

        return $result;
    }

    /** "10" → +10 %, "-5.5" → -5.5 %, rounded half-up at paisa. */
    private function percentDelta(Money $base, string $percent): Money
    {
        $neg = str_starts_with($percent, '-');
        $delta = $base->percent(ltrim($percent, '+-'));

        return $neg ? Money::zero()->sub($delta) : $delta;
    }

    /** @param array<string, mixed> $spec */
    private function assertSpec(array $spec): void
    {
        if (! in_array($spec['mode'] ?? null, ['percent', 'fixed'], true) || ! isset($spec['amount']) || ! preg_match('/^[+-]?\d+(\.\d{1,2})?$/', (string) $spec['amount'])) {
            throw new ApiException('VALIDATION', 422, '', ['amount' => ['errors.VALIDATION']]);
        }
        $columns = $spec['columns'] ?? [];
        if ($columns === [] || array_diff($columns, self::COLUMNS) !== []) {
            throw new ApiException('VALIDATION', 422, '', ['columns' => ['errors.VALIDATION']]);
        }
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return Builder<ProductVariant>
     */
    private function variantQuery(array $filter): Builder
    {
        $q = ProductVariant::query();
        if (! empty($filter['variant_ids'])) {
            $q->whereIn('id', $filter['variant_ids']);
        }
        $q->whereHas('product', function ($p) use ($filter): void {
            if (! empty($filter['product_ids'])) {
                $p->whereIn('id', $filter['product_ids']);
            }
            if (! empty($filter['brand_id'])) {
                $p->where('brand_id', $filter['brand_id']);
            }
            if (! empty($filter['mirror_type'])) {
                $p->where('mirror_type', $filter['mirror_type']);
            }
            if (! empty($filter['category_id'])) {
                $p->whereIn('category_id', $this->categoryTree((int) $filter['category_id']));
            }
        });

        return $q;
    }

    /** @return array<int, int> category id plus all descendants */
    public function categoryTree(int $id): array
    {
        $ids = [$id];
        $frontier = [$id];
        for ($i = 0; $i < 8 && $frontier; $i++) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
