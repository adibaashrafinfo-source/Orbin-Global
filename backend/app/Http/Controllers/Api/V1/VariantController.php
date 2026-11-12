<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BulkPriceRequest;
use App\Http\Requests\Catalog\MatrixRequest;
use App\Http\Requests\Catalog\VariantRequest;
use App\Http\Resources\VariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\MatrixService;
use App\Services\Catalog\PriceService;
use App\Services\Catalog\VariantService;
use App\Services\PermissionService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-302..306: variants, matrix generator, clone, barcode, bulk price. Price columns need pricing.manage. */
class VariantController extends Controller
{
    private const RELATIONS = ['glassType', 'tint', 'colorFinish', 'crossReferences', 'fitments.vehicleModel.make', 'images'];

    public function __construct(
        private readonly VariantService $variants,
        private readonly MatrixService $matrix,
        private readonly PriceService $prices,
        private readonly PermissionService $permissions,
    ) {}

    public function store(VariantRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        $this->guardPrices($request, $data, null);
        $variant = $this->variants->create($product, $data);

        return (new VariantResource($variant->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(ProductVariant $variant): VariantResource
    {
        return new VariantResource($variant->load(self::RELATIONS));
    }

    public function update(VariantRequest $request, ProductVariant $variant): VariantResource
    {
        $data = $request->validated();
        $this->guardPrices($request, $data, $variant);

        return new VariantResource($this->variants->update($variant, $data)->load(self::RELATIONS));
    }

    public function destroy(ProductVariant $variant): JsonResponse
    {
        $this->variants->delete($variant);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function clone(Request $request, ProductVariant $variant): JsonResponse
    {
        $overrides = $request->validate(VariantRequest::rulesFor(true) + [
            'copy_fitments' => ['sometimes', 'boolean'], 'copy_cross_references' => ['sometimes', 'boolean'],
        ]);
        $copyFit = (bool) ($overrides['copy_fitments'] ?? true);
        $copyXref = (bool) ($overrides['copy_cross_references'] ?? false);
        unset($overrides['copy_fitments'], $overrides['copy_cross_references']);
        $this->guardPrices($request, $overrides, $variant);
        $clone = $this->variants->cloneVariant($variant, $overrides, $copyFit, $copyXref);

        return (new VariantResource($clone->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function generateBarcode(ProductVariant $variant): VariantResource
    {
        return new VariantResource($this->variants->generateBarcode($variant)->load(self::RELATIONS));
    }

    /** POST /products/{product}/variants/matrix  (dry_run=true returns the plan without writing) */
    public function matrix(MatrixRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        $this->guardPrices($request, $data['defaults'] ?? [], null);
        $result = $this->matrix->generate($product, $data, (bool) ($data['dry_run'] ?? false));

        return response()->json(['data' => $result], ($data['dry_run'] ?? false) ? 200 : 201);
    }

    /** POST /variants/bulk-price  (dry_run=true previews) */
    public function bulkPrice(BulkPriceRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->prices->run($data, (bool) ($data['dry_run'] ?? false))]);
    }

    /** "12500" equals "12500.00"; null equals null only. */
    private function samePrice(mixed $new, mixed $old): bool
    {
        if ($new === null || $old === null) {
            return $new === $old;
        }

        return Money::of((string) $new)->paisa === Money::of((string) $old)->paisa;
    }

    /** @param array<string, mixed> $data */
    private function guardPrices(Request $request, array $data, ?ProductVariant $current): void
    {
        /** @var User $user */
        $user = $request->user();
        if ($this->permissions->userHas($user, 'pricing.manage')) {
            return;
        }
        foreach (ProductVariant::PRICE_COLUMNS as $col) {
            if (array_key_exists($col, $data) && ($current === null || ! $this->samePrice($data[$col], $current->{$col}))) {
                throw new ApiException('PRICING_FORBIDDEN', 403);
            }
        }
    }
}
