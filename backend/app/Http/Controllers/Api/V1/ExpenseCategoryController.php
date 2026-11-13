<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** FR-1005: admin-managed categories. The one the system posts transfer charges to cannot be removed or switched off. */
class ExpenseCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ExpenseCategory::query()->orderBy('name_en')->get()->map(fn ($c) => $this->row($c))->values()]);
    }

    public function store(ExpenseCategoryRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->row(ExpenseCategory::query()->create($request->validated())->refresh())], 201);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $category): JsonResponse
    {
        $data = $request->validated();
        if ($category->code !== null && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw new ApiException('SYSTEM_CATEGORY', 422);
        }
        if (isset($data['parent_id']) && (int) $data['parent_id'] === $category->id) {
            throw new ApiException('VALIDATION', 422, '', ['parent_id' => ['errors.REQUIRED']]);
        }
        $category->update($data);

        return response()->json(['data' => $this->row($category->refresh())]);
    }

    public function destroy(ExpenseCategory $category): JsonResponse
    {
        if ($category->code !== null) {
            throw new ApiException('SYSTEM_CATEGORY', 422);
        }
        if (DB::table('expenses')->where('category_id', $category->id)->whereNull('deleted_at')->exists() || ExpenseCategory::query()->where('parent_id', $category->id)->exists()) {
            throw new ApiException('IN_USE', 409);
        }
        $category->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function row(ExpenseCategory $c): array
    {
        return ['id' => $c->id, 'parent_id' => $c->parent_id, 'name_en' => $c->name_en, 'name_bn' => $c->name_bn, 'is_active' => $c->is_active, 'is_system' => $c->code !== null];
    }
}
