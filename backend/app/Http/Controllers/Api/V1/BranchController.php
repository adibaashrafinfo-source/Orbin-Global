<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\StoreBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** FR-105 Branches. */
class BranchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::query()->withCount('warehouses')->orderBy('id')->get());
    }

    public function store(StoreBranchRequest $request): BranchResource
    {
        $branch = DB::transaction(function () use ($request): Branch {
            $data = $request->validated();
            if (! empty($data['is_default'])) {
                Branch::query()->where('is_default', true)->get()->each(fn (Branch $b) => $b->update(['is_default' => false]));
            }

            return Branch::query()->create($data);
        });

        return new BranchResource($branch);
    }

    public function update(StoreBranchRequest $request, Branch $branch): BranchResource
    {
        DB::transaction(function () use ($request, $branch): void {
            $data = $request->validated();
            if (! empty($data['is_default'])) {
                Branch::query()->where('is_default', true)->where('id', '!=', $branch->id)->get()->each(fn (Branch $b) => $b->update(['is_default' => false]));
            }
            $branch->update($data);
        });

        return new BranchResource($branch->refresh()->loadCount('warehouses'));
    }
}
