<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\StoreStorageLocationRequest;
use App\Http\Resources\StorageLocationResource;
use App\Models\StorageLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** FR-105 Storage locations (rack / shelf / bin). */
class StorageLocationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StorageLocation::query()->orderBy('warehouse_id')->orderBy('code');
        if ($warehouseId = $request->input('filter.warehouse_id')) {
            $query->where('warehouse_id', (int) $warehouseId);
        }

        return StorageLocationResource::collection($query->get());
    }

    public function store(StoreStorageLocationRequest $request): StorageLocationResource
    {
        return new StorageLocationResource(StorageLocation::query()->create($request->validated()));
    }

    public function update(StoreStorageLocationRequest $request, StorageLocation $storageLocation): StorageLocationResource
    {
        $storageLocation->update($request->validated());

        return new StorageLocationResource($storageLocation->refresh());
    }
}
