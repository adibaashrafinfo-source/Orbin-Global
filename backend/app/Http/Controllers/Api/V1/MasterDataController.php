<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterDataRequest;
use App\Models\Category;
use App\Models\TaxRate;
use App\Support\MasterData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** FR-201: CRUD, search, activate, reorder and in-use guards for the simple master-data lists. */
class MasterDataController extends Controller
{
    /** @return array<string, mixed> */
    private function config(string $resource): array
    {
        return MasterData::get($resource) ?? throw ApiException::notFound();
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        $cfg = $this->config($resource);
        $class = $cfg['model'];
        $query = $class::query();
        foreach ($cfg['counts'] as $relation => $_) {
            $query->withCount($relation);
        }
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($cfg, $search): void {
                foreach ($cfg['search'] as $col) {
                    $q->orWhere($col, 'like', "%{$search}%");
                }
            });
        }
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        foreach ($cfg['filters'] as $filter) {
            if ($request->filled("filter.{$filter}")) {
                $query->where($filter, $request->input("filter.{$filter}"));
            }
        }
        foreach ($cfg['order'] as $col) {
            $query->orderBy($col);
        }
        $rows = $query->limit(5000)->get()->map(fn (Model $m) => $this->present($m, $cfg));

        return response()->json(['data' => $rows, 'meta' => ['total' => $rows->count()]]);
    }

    public function store(MasterDataRequest $request, string $resource): JsonResponse
    {
        $cfg = $this->config($resource);
        $data = $this->prepare($resource, $request->validated(), null);
        $model = DB::transaction(function () use ($cfg, $data, $resource) {
            if ($resource === 'tax-rates' && ! empty($data['is_default'])) {
                $this->clearDefault();
            }

            return $cfg['model']::query()->create($data);
        });

        return response()->json(['data' => $this->present($model->refresh(), $cfg)], 201);
    }

    public function update(MasterDataRequest $request, string $resource, int $id): JsonResponse
    {
        $cfg = $this->config($resource);
        $model = $cfg['model']::query()->findOrFail($id);
        $data = $this->prepare($resource, $request->validated(), $model);
        DB::transaction(function () use ($model, $data, $resource): void {
            if ($resource === 'tax-rates') {
                if (! empty($data['is_default'])) {
                    $this->clearDefault($model->getKey());
                } elseif (array_key_exists('is_default', $data) && $model->getAttribute('is_default')) {
                    throw new ApiException('DEFAULT_REQUIRED', 422); // choose another default instead of unsetting this one
                }
            }
            $model->update($data);
        });

        return response()->json(['data' => $this->present($model->refresh(), $cfg)]);
    }

    public function destroy(string $resource, int $id): JsonResponse
    {
        $cfg = $this->config($resource);
        $model = $cfg['model']::query()->findOrFail($id);
        foreach ($cfg['in_use'] as [$table, $column]) {
            $inUse = DB::table($table)->where($column, $id)->whereNull('deleted_at')->exists();
            if ($inUse) {
                throw new ApiException('IN_USE', 409); // deactivate instead of deleting
            }
        }
        if ($resource === 'tax-rates' && $model->getAttribute('is_default')) {
            throw new ApiException('DEFAULT_REQUIRED', 422);
        }
        $model->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** PUT /{resource}/reorder  {ids: [3,1,2]} — sort_order becomes 10,20,30 in that order. */
    public function reorder(Request $request, string $resource): JsonResponse
    {
        $cfg = $this->config($resource);
        abort_unless($cfg['reorder'], 404);
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        DB::transaction(function () use ($cfg, $ids): void {
            foreach (array_values($ids) as $i => $id) {
                $cfg['model']::query()->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        return response()->json(['data' => ['reordered' => count($ids)]]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(string $resource, array $data, ?Model $existing): array
    {
        if ($resource === 'vehicle-models' && array_key_exists('generation_code', $data)) {
            $data['generation_code'] = trim((string) $data['generation_code']);
        }
        if ($resource === 'categories') {
            if (isset($data['parent_id']) && $existing && $this->isSelfOrDescendant((int) $existing->getKey(), (int) $data['parent_id'])) {
                throw new ApiException('CATEGORY_CYCLE', 422);
            }
            if ($existing === null || array_key_exists('slug', $data)) {
                $explicit = trim((string) ($data['slug'] ?? ''));
                $source = $explicit !== '' ? $explicit : (string) ($data['name_en'] ?? $existing?->getAttribute('name_en'));
                $data['slug'] = $this->uniqueSlug($source, $existing?->getKey());
            }
        }
        if ($resource === 'vehicle-models') {
            $this->assertModelUnique($data, $existing);
        }
        if ($existing === null && ! array_key_exists('sort_order', $data) && (MasterData::get($resource)['reorder'] ?? false)) {
            $data['sort_order'] = $this->nextSortOrder($resource, $data);
        }

        return $data;
    }

    /** New rows of an orderable list go to the end of their siblings, not to the top. */
    private function nextSortOrder(string $resource, array $data): int
    {
        $query = $resource === 'categories'
            ? DB::table('categories')->where('parent_id', $data['parent_id'] ?? null)
            : DB::table('vehicle_makes');

        return ((int) $query->whereNull('deleted_at')->max('sort_order')) + 10;
    }

    /** @param array<string, mixed> $data */
    private function assertModelUnique(array $data, ?Model $existing): void
    {
        $makeId = $data['make_id'] ?? $existing?->getAttribute('make_id');
        $name = $data['name'] ?? $existing?->getAttribute('name');
        $gen = $data['generation_code'] ?? $existing?->getAttribute('generation_code') ?? '';
        $dup = DB::table('vehicle_models')->where(['make_id' => $makeId, 'name' => $name, 'generation_code' => $gen])
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->getKey()))->exists();
        if ($dup) {
            throw new ApiException('VALIDATION', 422, '', ['name' => ['errors.CONFLICT']]);
        }
    }

    private function isSelfOrDescendant(int $categoryId, int $candidateParentId): bool
    {
        $cursor = $candidateParentId;
        $guard = 0;
        while ($cursor && $guard++ < 50) {
            if ($cursor === $categoryId) {
                return true;
            }
            $cursor = (int) DB::table('categories')->where('id', $cursor)->value('parent_id');
        }

        return false;
    }

    private function uniqueSlug(string $source, ?int $ignoreId): string
    {
        $base = Str::slug($source) ?: 'category';
        $slug = $base;
        $n = 2;
        while (Category::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function clearDefault(?int $exceptId = null): void
    {
        TaxRate::query()->where('is_default', true)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->get()
            ->each(fn ($t) => $t->update(['is_default' => false]));
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    private function present(Model $m, array $cfg): array
    {
        if ($cfg['counts'] !== [] && ! array_key_exists(array_values($cfg['counts'])[0], $m->getAttributes())) {
            $m->loadCount(array_keys($cfg['counts']));
        }
        $out = $m->only($cfg['columns']);
        foreach ($cfg['counts'] as $_ => $attr) {
            $out[$attr] = $m->getAttribute($attr);
        }

        return $out;
    }
}
