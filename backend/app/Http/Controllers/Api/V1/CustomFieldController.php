<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CustomFieldRequest;
use App\Models\CustomFieldDef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** FR-203 Custom field builder for product, variant, customer and supplier. */
class CustomFieldController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CustomFieldDef::query()->orderBy('entity')->orderBy('sort_order')->orderBy('id');
        if ($entity = $request->input('entity')) {
            $q->where('entity', $entity);
        }
        if ($request->has('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        return response()->json(['data' => $q->get()->map(fn ($d) => $this->present($d))]);
    }

    public function store(CustomFieldRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['sort_order'] ??= ((int) CustomFieldDef::query()->where('entity', $data['entity'])->max('sort_order')) + 10;
        $def = CustomFieldDef::query()->create($data);

        return response()->json(['data' => $this->present($def->refresh())], 201);
    }

    public function update(CustomFieldRequest $request, CustomFieldDef $field): JsonResponse
    {
        $field->update($request->validated());

        return response()->json(['data' => $this->present($field->refresh())]);
    }

    /** Stored values stay on records; a deleted field simply stops being shown and validated. */
    public function destroy(CustomFieldDef $field): JsonResponse
    {
        $field->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        DB::transaction(function () use ($ids): void {
            foreach (array_values($ids) as $i => $id) {
                CustomFieldDef::query()->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        return response()->json(['data' => ['reordered' => count($ids)]]);
    }

    /** @return array<string, mixed> */
    private function present(CustomFieldDef $d): array
    {
        return $d->only(['id', 'entity', 'key', 'label_en', 'label_bn', 'field_type', 'options', 'is_required', 'show_on_pos_card', 'show_on_invoice', 'is_searchable', 'sort_order', 'is_active']);
    }
}
