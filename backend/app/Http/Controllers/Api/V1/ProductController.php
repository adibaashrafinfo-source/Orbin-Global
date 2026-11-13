<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\PriceService;
use App\Services\Catalog\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** FR-301..302, 304, 308: product list with filters, editor save, publish, clone. */
class ProductController extends Controller
{
    private const SORTS = ['name' => 'name_en', 'code' => 'code', 'created' => 'created_at', 'updated' => 'updated_at'];

    public function __construct(private readonly ProductService $products, private readonly PriceService $prices) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'max:30'], 'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $query = Product::query()->with(['category', 'brand', 'qualityGrade', 'unit'])->withCount('variants')->withMin('variants', 'retail_price')->withMax('variants', 'retail_price')
            ->addSelect(['stock_total' => DB::table('stock_levels as l')->join('product_variants as v', 'v.id', '=', 'l.variant_id')->whereColumn('v.product_id', 'products.id')->whereNull('v.deleted_at')->selectRaw('COALESCE(SUM(l.qty_on_hand), 0)')]);
        $this->applyFilters($query, $request);
        $sort = (string) $request->input('sort', 'name');
        $desc = str_starts_with($sort, '-');
        $col = self::SORTS[ltrim($sort, '-')] ?? 'name_en';
        $query->orderBy($col, $desc ? 'desc' : 'asc')->orderBy('id');
        $page = $query->paginate((int) $request->input('per_page', 25));

        return ProductResource::collection($page->items())->additional(['meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->detail($product));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated());

        return (new ProductResource($this->detail($product)))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource($this->detail($this->products->update($product, $request->validated())));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->products->delete($product);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function publish(Product $product): ProductResource
    {
        return new ProductResource($this->detail($this->products->publish($product)));
    }

    public function deactivate(Request $request, Product $product): ProductResource
    {
        return new ProductResource($this->detail($this->products->deactivate($product, $request->boolean('confirm'))));
    }

    public function clone(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['code' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-]*$/']]);
        $copy = $this->products->cloneProduct($product, $data['code'] ?? null);

        return (new ProductResource($this->detail($copy)))->response()->setStatusCode(201);
    }

    private function detail(Product $product): Product
    {
        return $product->load(['variants' => fn ($q) => $q->withSum('stockLevels as stock_on_hand', 'qty_on_hand'), 'category', 'brand', 'qualityGrade', 'unit', 'images', 'variants.glassType', 'variants.tint', 'variants.colorFinish', 'variants.crossReferences', 'variants.fitments.vehicleModel.make', 'variants.images', 'variants.bundleItems.component']);
    }

    /** @param Builder<Product> $query */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = mb_strtolower(trim((string) $request->input('search', '')))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn ($q) => $q->where('search_text', 'like', $like)->orWhereHas('variants', fn ($v) => $v->where('search_text', 'like', $like)));
        }
        foreach (['mirror_type', 'brand_id', 'quality_grade_id', 'origin_country_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }
        if ($request->filled('filter.category_id')) {
            $query->whereIn('category_id', $this->prices->categoryTree((int) $request->input('filter.category_id')));
        }
        if (in_array($request->input('filter.stock'), ['in', 'out'], true)) {
            $has = fn ($v) => $v->whereHas('stockLevels', fn ($l) => $l->where('qty_on_hand', '>', 0));
            $request->input('filter.stock') === 'in' ? $query->whereHas('variants', $has) : $query->whereDoesntHave('variants', $has);
        }
        if ($request->has('filter.is_active') && $request->input('filter.is_active') !== '') {
            $query->where('is_active', filter_var($request->input('filter.is_active'), FILTER_VALIDATE_BOOL));
        }
        $variantFilters = array_filter([
            'glass_type_id' => $request->input('filter.glass_type_id'), 'position' => $request->input('filter.position'),
        ], fn ($v) => $v !== null && $v !== '');
        $makeId = $request->input('filter.make_id');
        $modelId = $request->input('filter.model_id');
        if ($variantFilters !== [] || $makeId || $modelId) {
            $query->whereHas('variants', function ($v) use ($variantFilters, $makeId, $modelId): void {
                $v->where($variantFilters);
                if ($makeId || $modelId) {
                    $v->whereHas('fitments', function ($f) use ($makeId, $modelId): void {
                        if ($modelId) {
                            $f->where('vehicle_model_id', $modelId);
                        }
                        if ($makeId) {
                            $f->whereHas('vehicleModel', fn ($m) => $m->where('make_id', $makeId));
                        }
                    });
                }
            });
        }
    }
}
