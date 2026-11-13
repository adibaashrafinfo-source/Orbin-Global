<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Http\Resources\StockDocumentResource;
use App\Models\Attachment;
use App\Models\StockAdjustment;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\PermissionService;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FR-403 */
class StockAdjustmentController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly StockAdjustmentService $adjustments, private readonly PermissionService $permissions, private readonly UploadService $uploads) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockAdjustment::query()->with(['warehouse', 'reason'])->orderByDesc('id');
        foreach (['status', 'warehouse_id', 'reason_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }
        if ($q = trim((string) $request->input('search', ''))) {
            $query->where('adj_no', 'like', '%'.$q.'%');
        }

        return $this->paged($query, $request, StockDocumentResource::class);
    }

    public function show(StockAdjustment $stockAdjustment): StockDocumentResource
    {
        return new StockDocumentResource($stockAdjustment->load(['warehouse', 'reason', 'items.variant']));
    }

    public function store(StockAdjustmentRequest $request): JsonResponse
    {
        return (new StockDocumentResource($this->adjustments->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(StockAdjustmentRequest $request, StockAdjustment $stockAdjustment): StockDocumentResource
    {
        return new StockDocumentResource($this->adjustments->update($stockAdjustment, $request->validated()));
    }

    public function submit(StockAdjustment $stockAdjustment): StockDocumentResource
    {
        return new StockDocumentResource($this->adjustments->submit($stockAdjustment));
    }

    public function post(Request $request, StockAdjustment $stockAdjustment): StockDocumentResource
    {
        return new StockDocumentResource($this->adjustments->post($stockAdjustment, $this->canApprove($request)));
    }

    public function reject(Request $request, StockAdjustment $stockAdjustment): StockDocumentResource
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return new StockDocumentResource($this->adjustments->reject($stockAdjustment, $data['reason'] ?? null));
    }

    public function destroy(StockAdjustment $stockAdjustment): JsonResponse
    {
        $this->adjustments->delete($stockAdjustment);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** A photo of the damage, kept privately and linked from an item by its id. */
    public function photo(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:8192']]);
        if (! in_array($stockAdjustment->status, ['draft', 'rejected'], true)) {
            throw new ApiException('INVALID_STATE', 409);
        }
        $file = $request->file('file');
        $mime = $this->uploads->sniffMime($file);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ApiException('UPLOAD_INVALID', 422, '', ['file' => ['errors.UPLOAD_INVALID']]);
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $path = $file->storeAs('adjustments/'.now()->format('Y/m'), bin2hex(random_bytes(12)).'.'.$ext, 'local');
        $att = Attachment::query()->create(['entity_table' => 'stock_adjustments', 'entity_id' => $stockAdjustment->id, 'storage_bucket' => 'local', 'storage_path' => $path,
            'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 191), 'mime_type' => $mime, 'size_bytes' => $file->getSize(), 'label' => 'adjustment photo']);

        return response()->json(['data' => ['id' => $att->id, 'file_name' => $att->file_name]], 201);
    }

    public function attachment(Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->entity_table === 'stock_adjustments' && Storage::disk('local')->exists($attachment->storage_path), 404);

        return Storage::disk('local')->response($attachment->storage_path, $attachment->file_name, ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function canApprove(Request $request): bool
    {
        return $this->permissions->userHas($request->user(), 'inventory.adjust.approve');
    }
}
