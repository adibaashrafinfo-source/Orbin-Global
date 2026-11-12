<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Catalog\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Product and variant images (spec 4.4). */
class ImageController extends Controller
{
    public function __construct(private readonly ImageService $images) {}

    public function store(ImageRequest $request, Product $product): JsonResponse
    {
        $variant = $request->filled('variant_id') ? ProductVariant::query()->where('product_id', $product->id)->findOrFail((int) $request->input('variant_id')) : null;
        $image = $this->images->add($product, $variant, $request->file('file'), (string) $request->input('label', 'other'), $request->input('alt_text'));

        return (new ImageResource($image))->response()->setStatusCode(201);
    }

    public function update(ImageRequest $request, ProductImage $image): ImageResource
    {
        $image->update($request->only(['label', 'alt_text']));

        return new ImageResource($image->refresh());
    }

    public function setPrimary(ProductImage $image): ImageResource
    {
        $this->images->setPrimary($image);

        return new ImageResource($image->refresh());
    }

    public function reorder(Request $request, Product $product): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        $this->images->reorder($product, $ids);

        return response()->json(['data' => ImageResource::collection($product->images()->get())]);
    }

    public function destroy(ProductImage $image): JsonResponse
    {
        $this->images->delete($image);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
