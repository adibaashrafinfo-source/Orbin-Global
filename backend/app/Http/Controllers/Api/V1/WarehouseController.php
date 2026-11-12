<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\StoreWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** FR-105 Warehouses (showroom, godown). */
class WarehouseController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WarehouseResource::collection(Warehouse::query()->withCount('locations')->orderBy('id')->get());
    }

    public function store(StoreWarehouseRequest $request): WarehouseResource
    {
        $warehouse = DB::transaction(function () use ($request): Warehouse {
            $data = $request->validated();
            if (! empty($data['is_default'])) {
                Warehouse::query()->where('is_default', true)->get()->each(fn (Warehouse $w) => $w->update(['is_default' => false]));
            }

            return Warehouse::query()->create($data);
        });

        return new WarehouseResource($warehouse);
    }

    public function update(StoreWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        DB::transaction(function () use ($request, $warehouse): void {
            $data = $request->validated();
            if (! empty($data['is_default'])) {
                Warehouse::query()->where('is_default', true)->where('id', '!=', $warehouse->id)->get()->each(fn (Warehouse $w) => $w->update(['is_default' => false]));
            }
            $warehouse->update($data);
        });

        return new WarehouseResource($warehouse->refresh()->loadCount('locations'));
    }
}
