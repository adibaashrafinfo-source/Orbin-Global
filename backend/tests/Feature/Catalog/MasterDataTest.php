<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\VehicleMake;
use App\Models\VehicleModel;

class MasterDataTest extends CatalogTestCase
{
    public function test_seeded_lists_are_readable_by_every_role(): void
    {
        foreach (self::ROLES as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/vehicle-makes')->assertOk()->assertJsonPath('meta.total', 20);
            $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('meta.total', 8);
            $this->getJson('/api/v1/units')->assertOk()->assertJsonPath('meta.total', 4);
            $this->getJson('/api/v1/tax-rates')->assertOk()->assertJsonPath('meta.total', 2);
        }
    }

    public function test_vehicle_makes_and_models_crud_uniqueness_and_guards(): void
    {
        $this->actingAsRole('manager');
        $make = $this->postJson('/api/v1/vehicle-makes', ['name' => 'Tesla'])->assertCreated()->json('data');
        $this->postJson('/api/v1/vehicle-makes', ['name' => 'Tesla'])->assertStatus(422)->assertJsonStructure(['fields' => ['name']]);
        $this->putJson("/api/v1/vehicle-makes/{$make['id']}", ['name' => 'Tesla Motors', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/vehicle-makes?search=tesla&active=0')->assertOk()->assertJsonPath('meta.total', 1);

        $m = $this->postJson('/api/v1/vehicle-models', ['make_id' => $make['id'], 'name' => 'Model 3', 'generation_code' => 'GEN1', 'year_from' => 2017])->assertCreated()->json('data');
        $this->postJson('/api/v1/vehicle-models', ['make_id' => $make['id'], 'name' => 'Model 3', 'generation_code' => 'GEN1'])->assertStatus(422);
        $this->postJson('/api/v1/vehicle-models', ['make_id' => $make['id'], 'name' => 'Model 3', 'generation_code' => 'GEN2'])->assertCreated(); // other generation is allowed
        $this->postJson('/api/v1/vehicle-models', ['make_id' => $make['id'], 'name' => 'X', 'year_from' => 2020, 'year_to' => 2010])->assertStatus(422);
        $this->getJson("/api/v1/vehicle-models?filter[make_id]={$make['id']}")->assertOk()->assertJsonPath('meta.total', 2);

        // a make with models cannot be deleted; a model that fits a variant cannot either
        $this->deleteJson("/api/v1/vehicle-makes/{$make['id']}")->assertStatus(409)->assertJsonPath('code', 'IN_USE');
        $v = $this->makeVariant($this->makeProduct());
        $this->postJson("/api/v1/variants/{$v['id']}/fitments", ['vehicle_model_id' => $m['id'], 'year_from' => 2018])->assertCreated();
        $this->deleteJson("/api/v1/vehicle-models/{$m['id']}")->assertStatus(409)->assertJsonPath('code', 'IN_USE');
        $unused = VehicleModel::query()->where('make_id', $make['id'])->where('generation_code', 'GEN2')->value('id');
        $this->deleteJson("/api/v1/vehicle-models/{$unused}")->assertOk();
        $this->assertSoftDeleted('vehicle_models', ['id' => $unused]);
    }

    public function test_makes_can_be_reordered(): void
    {
        $this->actingAsRole('manager');
        $ids = VehicleMake::query()->orderBy('sort_order')->pluck('id')->take(3)->all();
        $this->putJson('/api/v1/vehicle-makes/reorder', ['ids' => array_reverse($ids)])->assertOk();
        $this->assertSame(array_reverse($ids), VehicleMake::query()->orderBy('sort_order')->pluck('id')->take(3)->all());
        $this->putJson('/api/v1/units/reorder', ['ids' => [1]])->assertStatus(404); // not reorderable
    }

    public function test_categories_tree_slug_cycle_and_guards(): void
    {
        $this->actingAsRole('manager');
        $root = $this->postJson('/api/v1/categories', ['name_en' => 'Side Mirrors'])->assertCreated()->json('data'); // slug collides with seed
        $this->assertSame('side-mirrors-2', $root['slug']);
        $child = $this->postJson('/api/v1/categories', ['name_en' => 'Heated', 'name_bn' => 'গরম', 'parent_id' => $root['id']])->assertCreated()->json('data');
        $this->assertSame('heated', $child['slug']);
        $this->putJson("/api/v1/categories/{$root['id']}", ['parent_id' => $child['id']])->assertStatus(422)->assertJsonPath('code', 'CATEGORY_CYCLE');
        $this->putJson("/api/v1/categories/{$root['id']}", ['parent_id' => $root['id']])->assertStatus(422)->assertJsonPath('code', 'CATEGORY_CYCLE');
        $this->deleteJson("/api/v1/categories/{$root['id']}")->assertStatus(409)->assertJsonPath('code', 'IN_USE'); // has a child
        $this->getJson("/api/v1/categories?filter[parent_id]={$root['id']}")->assertOk()->assertJsonPath('meta.total', 1);

        $pid = $this->makeProduct(['category_id' => $child['id']]);
        $this->deleteJson("/api/v1/categories/{$child['id']}")->assertStatus(409); // used by a product
        $this->deleteJson("/api/v1/products/{$pid}")->assertOk();
        $this->deleteJson("/api/v1/categories/{$child['id']}")->assertOk();
        $this->deleteJson("/api/v1/categories/{$root['id']}")->assertOk();
        $this->assertSame(1, Category::withTrashed()->where('slug', 'heated')->count());
    }

    public function test_units_and_tax_rates(): void
    {
        $this->actingAsRole('manager');
        $this->postJson('/api/v1/units', ['code' => 'kit', 'name_en' => 'Kit', 'name_bn' => 'কিট'])->assertCreated();
        $this->postJson('/api/v1/units', ['code' => 'kit', 'name_en' => 'Dup'])->assertStatus(422);
        $this->postJson('/api/v1/units', ['code' => 'Bad Code', 'name_en' => 'x'])->assertStatus(422);
        $pcs = Unit::query()->where('code', 'pcs')->value('id');
        $this->makeProduct();
        $this->deleteJson("/api/v1/units/{$pcs}")->assertStatus(409)->assertJsonPath('code', 'IN_USE');

        $vat15 = TaxRate::query()->where('code', 'VAT15')->value('id');
        $vat0 = TaxRate::query()->where('code', 'VAT0')->value('id');
        $this->putJson("/api/v1/tax-rates/{$vat15}", ['is_default' => true])->assertOk();
        $this->assertSame([$vat15], TaxRate::query()->where('is_default', true)->pluck('id')->all()); // only one default
        $this->deleteJson("/api/v1/tax-rates/{$vat15}")->assertStatus(422)->assertJsonPath('code', 'DEFAULT_REQUIRED');
        $this->putJson("/api/v1/tax-rates/{$vat15}", ['is_default' => false])->assertStatus(422)->assertJsonPath('code', 'DEFAULT_REQUIRED');
        $this->postJson('/api/v1/tax-rates', ['code' => 'VAT5', 'name' => 'VAT 5%', 'rate_percent' => 5])->assertCreated();
        $this->postJson('/api/v1/tax-rates', ['code' => 'BAD', 'name' => 'Bad', 'rate_percent' => -1])->assertStatus(422);
        $this->postJson('/api/v1/tax-rates', ['code' => 'BAD2', 'name' => 'Bad', 'rate_percent' => 101])->assertStatus(422);
        $this->deleteJson("/api/v1/tax-rates/{$vat0}")->assertOk();
    }

    public function test_brands(): void
    {
        $this->actingAsRole('manager');
        $japan = $this->lookup('origin_country', 'japan');
        $b = $this->postJson('/api/v1/product-brands', ['name' => 'Toyota Genuine', 'is_oem' => true, 'country_id' => $japan, 'website' => 'https://example.com'])->assertCreated()->json('data');
        $this->postJson('/api/v1/product-brands', ['name' => 'Toyota Genuine'])->assertStatus(422);
        $this->makeProduct(['brand_id' => $b['id']]);
        $this->deleteJson("/api/v1/product-brands/{$b['id']}")->assertStatus(409);
        $this->assertStringContainsString('toyota genuine', Product::query()->value('search_text'));
    }

    public function test_writes_are_audited(): void
    {
        $this->actingAsRole('manager');
        $this->postJson('/api/v1/vehicle-makes', ['name' => 'Lucid'])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'entity_table' => 'vehicle_makes']);
    }
}
