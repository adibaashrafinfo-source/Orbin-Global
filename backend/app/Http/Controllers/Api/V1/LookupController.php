<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lookups\LookupTypeRequest;
use App\Http\Requests\Lookups\LookupValueRequest;
use App\Models\LookupType;
use App\Models\LookupValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** FR-202 Lookup manager: types and values, admin-extensible. System types cannot be deleted, only extended. */
class LookupController extends Controller
{
    /** Tables and columns that reference lookup_values; a referenced value can be deactivated but not deleted. */
    private const REFERENCES = [
        ['products', 'quality_grade_id'], ['products', 'origin_country_id'], ['product_variants', 'glass_type_id'],
        ['product_variants', 'tint_id'], ['product_variants', 'color_finish_id'], ['product_brands', 'country_id'], ['vehicle_models', 'body_type_id'],
    ];

    public function index(Request $request): JsonResponse
    {
        $types = LookupType::query()->with(['values' => fn ($q) => $request->boolean('active') ? $q->where('is_active', true) : $q])->orderBy('id')->get();

        return response()->json(['data' => $types->map(fn (LookupType $t) => $this->type($t))]);
    }

    public function storeType(LookupTypeRequest $request): JsonResponse
    {
        $type = LookupType::query()->create($request->validated() + ['is_system' => false]);

        return response()->json(['data' => $this->type($type->load('values'))], 201);
    }

    public function updateType(LookupTypeRequest $request, LookupType $type): JsonResponse
    {
        $type->update($request->validated());

        return response()->json(['data' => $this->type($type->refresh()->load('values'))]);
    }

    public function destroyType(LookupType $type): JsonResponse
    {
        if ($type->is_system) {
            throw new ApiException('SYSTEM_ROW_PROTECTED', 409);
        }
        if ($this->anyValueInUse($type->values()->pluck('id')->all())) {
            throw new ApiException('IN_USE', 409);
        }
        DB::transaction(function () use ($type): void {
            $type->values()->get()->each->delete();
            $type->delete();
        });

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function storeValue(LookupValueRequest $request, LookupType $type): JsonResponse
    {
        $data = $request->validated();
        $data['code'] ??= Str::slug((string) ($data['label_en'] ?? ''), '_');
        $data['sort_order'] ??= ((int) $type->values()->max('sort_order')) + 10;
        $value = $type->values()->create($data);

        return response()->json(['data' => $this->value($value)], 201);
    }

    public function updateValue(LookupValueRequest $request, LookupValue $value): JsonResponse
    {
        $value->update($request->validated());

        return response()->json(['data' => $this->value($value->refresh())]);
    }

    public function destroyValue(LookupValue $value): JsonResponse
    {
        if ($this->anyValueInUse([$value->id])) {
            throw new ApiException('IN_USE', 409);
        }
        $value->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function reorderValues(Request $request, LookupType $type): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        DB::transaction(function () use ($type, $ids): void {
            foreach (array_values($ids) as $i => $id) {
                LookupValue::query()->where('type_id', $type->id)->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        return response()->json(['data' => ['reordered' => count($ids)]]);
    }

    /** @param array<int, int> $valueIds */
    private function anyValueInUse(array $valueIds): bool
    {
        if ($valueIds === []) {
            return false;
        }
        foreach (self::REFERENCES as [$table, $column]) {
            if (DB::table($table)->whereIn($column, $valueIds)->whereNull('deleted_at')->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function type(LookupType $t): array
    {
        return [
            'id' => $t->id, 'key' => $t->key, 'name_en' => $t->name_en, 'name_bn' => $t->name_bn, 'description' => $t->description,
            'is_system' => $t->is_system, 'is_active' => $t->is_active,
            'values' => $t->relationLoaded('values') ? $t->values->map(fn (LookupValue $v) => $this->value($v))->values() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function value(LookupValue $v): array
    {
        return ['id' => $v->id, 'type_id' => $v->type_id, 'code' => $v->code, 'label_en' => $v->label_en, 'label_bn' => $v->label_bn, 'sort_order' => $v->sort_order, 'is_active' => $v->is_active];
    }
}
