<?php

namespace App\Support;

use App\Models\Category;
use App\Models\ProductBrand;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Validation\Rule;

/**
 * Registry for the simple admin-editable lists (FR-201): vehicle makes and models, brands, categories, units, tax rates.
 * One controller serves them all; this file holds what differs: model, validation, search, ordering and in-use guards.
 */
final class MasterData
{
    /**
     * @return array<string, array{model: class-string, columns: array<int, string>, search: array<int, string>, order: array<int, string>, counts: array<string, string>, in_use: array<int, array{0: string, 1: string}>, reorder: bool, with: array<int, string>, filters: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            'vehicle-makes' => [
                'model' => VehicleMake::class,
                'columns' => ['id', 'name', 'logo_url', 'sort_order', 'is_active'],
                'search' => ['name'], 'order' => ['sort_order', 'name'],
                'counts' => ['models' => 'models_count'],
                'in_use' => [['vehicle_models', 'make_id']], 'reorder' => true, 'with' => [], 'filters' => [],
            ],
            'vehicle-models' => [
                'model' => VehicleModel::class,
                'columns' => ['id', 'make_id', 'name', 'generation_code', 'body_type_id', 'year_from', 'year_to', 'is_active'],
                'search' => ['name', 'generation_code'], 'order' => ['name', 'generation_code'],
                'counts' => [],
                'in_use' => [['vehicle_fitments', 'vehicle_model_id']], 'reorder' => false, 'with' => [], 'filters' => ['make_id'],
            ],
            'product-brands' => [
                'model' => ProductBrand::class,
                'columns' => ['id', 'name', 'country_id', 'logo_url', 'website', 'is_oem', 'is_active'],
                'search' => ['name'], 'order' => ['name'],
                'counts' => [],
                'in_use' => [['products', 'brand_id']], 'reorder' => false, 'with' => [], 'filters' => [],
            ],
            'categories' => [
                'model' => Category::class,
                'columns' => ['id', 'parent_id', 'name_en', 'name_bn', 'slug', 'sort_order', 'is_active'],
                'search' => ['name_en', 'name_bn', 'slug'], 'order' => ['sort_order', 'name_en'],
                'counts' => ['children' => 'children_count'],
                'in_use' => [['products', 'category_id'], ['categories', 'parent_id']], 'reorder' => true, 'with' => [], 'filters' => ['parent_id'],
            ],
            'units' => [
                'model' => Unit::class,
                'columns' => ['id', 'code', 'name_en', 'name_bn', 'is_pair_unit', 'is_active'],
                'search' => ['code', 'name_en', 'name_bn'], 'order' => ['id'],
                'counts' => [],
                'in_use' => [['products', 'unit_id']], 'reorder' => false, 'with' => [], 'filters' => [],
            ],
            'tax-rates' => [
                'model' => TaxRate::class,
                'columns' => ['id', 'code', 'name', 'rate_percent', 'is_default', 'is_active'],
                'search' => ['code', 'name'], 'order' => ['id'],
                'counts' => [],
                'in_use' => [['products', 'tax_rate_id'], ['product_variants', 'tax_rate_id']], 'reorder' => false, 'with' => [], 'filters' => [],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $resource): ?array
    {
        return self::all()[$resource] ?? null;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(string $resource, ?int $id): array
    {
        $req = $id ? 'sometimes' : 'required';
        $unique = fn (string $table, string $col) => Rule::unique($table, $col)->ignore($id)->whereNull('deleted_at');
        $active = ['sometimes', 'boolean'];

        return match ($resource) {
            'vehicle-makes' => [
                'name' => [$req, 'string', 'max:191', $unique('vehicle_makes', 'name')],
                'logo_url' => ['sometimes', 'nullable', 'string', 'max:255'],
                'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => $active,
            ],
            'vehicle-models' => [
                'make_id' => [$req, 'integer', Rule::exists('vehicle_makes', 'id')->whereNull('deleted_at')],
                'name' => [$req, 'string', 'max:191'],
                'generation_code' => ['sometimes', 'nullable', 'string', 'max:100'],
                'body_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lookup_values', 'id')],
                'year_from' => ['sometimes', 'nullable', 'integer', 'between:1950,2100'],
                'year_to' => ['sometimes', 'nullable', 'integer', 'between:1950,2100', 'gte:year_from'],
                'is_active' => $active,
            ],
            'product-brands' => [
                'name' => [$req, 'string', 'max:191', $unique('product_brands', 'name')],
                'country_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lookup_values', 'id')],
                'logo_url' => ['sometimes', 'nullable', 'string', 'max:255'],
                'website' => ['sometimes', 'nullable', 'string', 'max:255'],
                'is_oem' => ['sometimes', 'boolean'], 'is_active' => $active,
            ],
            'categories' => [
                'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
                'name_en' => [$req, 'string', 'max:255'],
                'name_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
                'slug' => ['sometimes', 'nullable', 'string', 'max:191', 'regex:/^[a-z0-9\-]+$/'],
                'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => $active,
            ],
            'units' => [
                'code' => [$req, 'string', 'max:50', 'regex:/^[a-z0-9_\-]+$/', $unique('units', 'code')],
                'name_en' => [$req, 'string', 'max:255'],
                'name_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
                'is_pair_unit' => ['sometimes', 'boolean'], 'is_active' => $active,
            ],
            'tax-rates' => [
                'code' => [$req, 'string', 'max:50', 'regex:/^[A-Z0-9_\-]+$/', $unique('tax_rates', 'code')],
                'name' => [$req, 'string', 'max:255'],
                'rate_percent' => [$req, 'numeric', 'min:0', 'max:100'],
                'is_default' => ['sometimes', 'boolean'], 'is_active' => $active,
            ],
            default => [],
        };
    }
}
