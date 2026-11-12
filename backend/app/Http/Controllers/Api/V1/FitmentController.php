<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CrossReferenceRequest;
use App\Http\Requests\Catalog\FitmentRequest;
use App\Models\OemCrossReference;
use App\Models\ProductVariant;
use App\Models\VehicleFitment;
use App\Services\Catalog\VariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** FR-306 fitment editor and FR-307 cross-references. */
class FitmentController extends Controller
{
    public function __construct(private readonly VariantService $variants) {}

    public function storeFitment(FitmentRequest $request, ProductVariant $variant): JsonResponse
    {
        $d = $request->validated();
        $targets = ! empty($d['apply_to_all_variants']) ? $variant->product->variants()->pluck('id')->all() : [$variant->id];
        $created = 0;
        $skipped = 0;
        DB::transaction(function () use ($targets, $d, &$created, &$skipped): void {
            foreach ($targets as $id) {
                try {
                    $this->variants->addFitmentRow($id, (int) $d['vehicle_model_id'], (int) $d['year_from'], $d['year_to'] ?? null, $d['trim'] ?? null, $d['note'] ?? null);
                    $created++;
                } catch (ApiException $e) {
                    if ($e->errorCode !== 'FITMENT_DUPLICATE' || count($targets) === 1) {
                        throw $e;
                    }
                    $skipped++;
                }
            }
        });

        return response()->json(['data' => ['created' => $created, 'skipped' => $skipped]], 201);
    }

    public function destroyFitment(VehicleFitment $fitment): JsonResponse
    {
        $fitment->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Copy every fitment of this variant to other variants ("same as variant X"); duplicates are skipped. */
    public function copyFitments(Request $request, ProductVariant $variant): JsonResponse
    {
        $ids = $request->validate(['target_variant_ids' => ['required', 'array', 'min:1', 'max:200'], 'target_variant_ids.*' => ['integer']])['target_variant_ids'];
        $created = 0;
        $skipped = 0;
        DB::transaction(function () use ($variant, $ids, &$created, &$skipped): void {
            foreach (ProductVariant::query()->whereIn('id', $ids)->where('id', '!=', $variant->id)->pluck('id') as $targetId) {
                foreach ($variant->fitments as $f) {
                    try {
                        $this->variants->addFitmentRow($targetId, $f->vehicle_model_id, $f->year_from, $f->year_to, $f->trim, $f->note);
                        $created++;
                    } catch (ApiException $e) {
                        if ($e->errorCode !== 'FITMENT_DUPLICATE') {
                            throw $e;
                        }
                        $skipped++;
                    }
                }
            }
        });

        return response()->json(['data' => ['created' => $created, 'skipped' => $skipped]]);
    }

    public function storeCrossReference(CrossReferenceRequest $request, ProductVariant $variant): JsonResponse
    {
        $ref = $this->variants->addCrossReference($variant, $request->validated());

        return response()->json(['data' => $ref->only(['id', 'ref_type', 'number', 'number_normalized', 'brand', 'note'])], 201);
    }

    public function destroyCrossReference(OemCrossReference $reference): JsonResponse
    {
        $this->variants->removeCrossReference($reference);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
