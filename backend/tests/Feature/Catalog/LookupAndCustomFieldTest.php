<?php

namespace Tests\Feature\Catalog;

use App\Models\LookupType;
use App\Models\VehicleMake;

class LookupAndCustomFieldTest extends CatalogTestCase
{
    public function test_lookup_manager_extends_system_types_and_protects_them(): void
    {
        $this->actingAsRole('manager');
        $res = $this->getJson('/api/v1/lookups')->assertOk();
        $this->assertCount(9, $res->json('data'));
        $glass = collect($res->json('data'))->firstWhere('key', 'glass_type');
        $this->assertTrue($glass['is_system']);
        $this->assertCount(5, $glass['values']);

        $v = $this->postJson("/api/v1/lookups/{$glass['id']}/values", ['code' => 'tempered', 'label_en' => 'Tempered', 'label_bn' => 'টেম্পারড'])->assertCreated()->json('data');
        $this->assertSame(60, $v['sort_order']);
        $this->postJson("/api/v1/lookups/{$glass['id']}/values", ['code' => 'tempered', 'label_en' => 'Dup'])->assertStatus(422);
        $this->putJson("/api/v1/lookup-values/{$v['id']}", ['label_en' => 'Tempered glass', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $ids = collect($glass['values'])->pluck('id')->reverse()->values()->all();
        $this->putJson("/api/v1/lookups/{$glass['id']}/values/reorder", ['ids' => $ids])->assertOk();

        $this->deleteJson("/api/v1/lookups/{$glass['id']}")->assertStatus(409)->assertJsonPath('code', 'SYSTEM_ROW_PROTECTED');

        $custom = $this->postJson('/api/v1/lookups', ['key' => 'mount_style', 'name_en' => 'Mount style'])->assertCreated()->json('data');
        $this->assertFalse($custom['is_system']);
        $this->postJson('/api/v1/lookups', ['key' => 'mount_style', 'name_en' => 'Dup'])->assertStatus(422);
        $this->postJson('/api/v1/lookups', ['key' => 'Bad Key', 'name_en' => 'x'])->assertStatus(422);
        $this->putJson("/api/v1/lookups/{$custom['id']}", ['key' => 'renamed', 'name_en' => 'Mount'])->assertStatus(422); // key is permanent
        $this->putJson("/api/v1/lookups/{$custom['id']}", ['name_en' => 'Mount styles'])->assertOk();
        $this->deleteJson("/api/v1/lookups/{$custom['id']}")->assertOk();
        $this->assertSame(0, LookupType::query()->where('key', 'mount_style')->count());
    }

    public function test_a_lookup_value_in_use_can_be_deactivated_but_not_deleted(): void
    {
        $this->actingAsRole('manager');
        $convex = $this->lookup('glass_type', 'convex');
        $this->makeVariant($this->makeProduct(), ['glass_type_id' => $convex]);
        $this->deleteJson("/api/v1/lookup-values/{$convex}")->assertStatus(409)->assertJsonPath('code', 'IN_USE');
        $this->putJson("/api/v1/lookup-values/{$convex}", ['is_active' => false])->assertOk();
        $flat = $this->lookup('glass_type', 'flat');
        $this->deleteJson("/api/v1/lookup-values/{$flat}")->assertOk();
    }

    public function test_a_t015_admin_adds_lookup_value_custom_field_and_vehicle_model_and_uses_them_without_code_changes(): void
    {
        $this->actingAsRole('manager');
        $glass = LookupType::query()->where('key', 'glass_type')->value('id');
        $newGlass = $this->postJson("/api/v1/lookups/{$glass}/values", ['code' => 'photochromic', 'label_en' => 'Photochromic'])->assertCreated()->json('data.id');
        $make = VehicleMake($this)->id;
        $model = $this->postJson('/api/v1/vehicle-models', ['make_id' => $make, 'name' => 'Bolt', 'generation_code' => 'EV1'])->assertCreated()->json('data.id');
        $field = $this->postJson('/api/v1/custom-fields', [
            'entity' => 'variant', 'key' => 'mount_style', 'label_en' => 'Mount style', 'field_type' => 'select',
            'options' => [['value' => 'clip', 'label_en' => 'Clip'], ['value' => 'screw', 'label_en' => 'Screw']], 'show_on_pos_card' => true,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/custom-fields', ['entity' => 'product', 'key' => 'catalog_page', 'label_en' => 'Catalog page', 'field_type' => 'number', 'is_required' => true])->assertCreated();

        $pid = $this->makeProduct(['custom' => ['catalog_page' => 42, 'unknown_key' => 'dropped']]);
        $v = $this->makeVariant($pid, ['glass_type_id' => $newGlass, 'custom' => ['mount_style' => 'clip']]);
        $this->assertSame('Photochromic', $this->getJson("/api/v1/variants/{$v['id']}")->json('data.glass_type'));
        $this->assertEquals(['mount_style' => 'clip'], $this->getJson("/api/v1/variants/{$v['id']}")->json('data.custom'));
        $this->assertEquals(['catalog_page' => 42], $this->getJson("/api/v1/products/{$pid}")->json('data.custom'));
        $this->postJson("/api/v1/variants/{$v['id']}/fitments", ['vehicle_model_id' => $model, 'year_from' => 2020])->assertCreated();
        $this->assertSame($field['key'], 'mount_style');

        // invalid values are rejected
        $this->postJson("/api/v1/products/{$pid}/variants", $this->variantPayload(['position' => 'left', 'custom' => ['mount_style' => 'glue']]))->assertStatus(422)->assertJsonStructure(['fields' => ['custom.mount_style']]);
        $this->putJson("/api/v1/products/{$pid}", ['custom' => ['catalog_page' => 'abc']])->assertStatus(422)->assertJsonStructure(['fields' => ['custom.catalog_page']]);
    }

    public function test_required_custom_fields_are_enforced_when_publishing(): void
    {
        $this->actingAsRole('manager');
        $this->postJson('/api/v1/custom-fields', ['entity' => 'product', 'key' => 'catalog_page', 'label_en' => 'Catalog page', 'field_type' => 'number', 'is_required' => true])->assertCreated();
        $pid = $this->makeProduct();
        $this->makeVariant($pid);
        $this->postJson("/api/v1/products/{$pid}/publish")->assertStatus(422)->assertJsonPath('code', 'PRODUCT_INCOMPLETE')->assertJsonStructure(['fields' => ['custom.catalog_page']]);
        $this->putJson("/api/v1/products/{$pid}", ['custom' => ['catalog_page' => 7]])->assertOk();
        $this->postJson("/api/v1/products/{$pid}/publish")->assertOk();
    }

    public function test_custom_field_definitions_validate_and_reorder(): void
    {
        $this->actingAsRole('manager');
        $this->postJson('/api/v1/custom-fields', ['entity' => 'variant', 'key' => 'pick', 'label_en' => 'Pick', 'field_type' => 'select'])->assertStatus(422)->assertJsonStructure(['fields' => ['options']]);
        $a = $this->postJson('/api/v1/custom-fields', ['entity' => 'supplier', 'key' => 'rating', 'label_en' => 'Rating', 'field_type' => 'number'])->assertCreated()->json('data');
        $b = $this->postJson('/api/v1/custom-fields', ['entity' => 'supplier', 'key' => 'notes2', 'label_en' => 'Notes', 'field_type' => 'long_text'])->assertCreated()->json('data');
        $this->postJson('/api/v1/custom-fields', ['entity' => 'supplier', 'key' => 'rating', 'label_en' => 'Dup', 'field_type' => 'number'])->assertStatus(422);
        $this->postJson('/api/v1/custom-fields', ['entity' => 'nothing', 'key' => 'x1', 'label_en' => 'x', 'field_type' => 'text'])->assertStatus(422);
        $this->putJson('/api/v1/custom-fields/reorder', ['ids' => [$b['id'], $a['id']]])->assertOk();
        $this->assertSame(['notes2', 'rating'], collect($this->getJson('/api/v1/custom-fields?entity=supplier')->json('data'))->pluck('key')->all());
        $this->putJson("/api/v1/custom-fields/{$a['id']}", ['entity' => 'product'])->assertStatus(422); // entity is permanent
        $this->putJson("/api/v1/custom-fields/{$a['id']}", ['label_en' => 'Supplier rating', 'is_active' => false])->assertOk();
        $this->deleteJson("/api/v1/custom-fields/{$b['id']}")->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/custom-fields?entity=supplier')->json('data'));
    }

    public function test_a_custom_field_can_use_a_lookup_list_as_its_options(): void
    {
        $this->actingAsRole('manager');
        $this->postJson('/api/v1/custom-fields', ['entity' => 'product', 'key' => 'finish_pick', 'label_en' => 'Finish', 'field_type' => 'multi_select', 'options' => ['lookup' => 'color_finish']])->assertCreated();
        $pid = $this->makeProduct(['custom' => ['finish_pick' => ['black', 'chrome']]]);
        $this->assertEquals(['finish_pick' => ['black', 'chrome']], $this->getJson("/api/v1/products/{$pid}")->json('data.custom'));
        $this->putJson("/api/v1/products/{$pid}", ['custom' => ['finish_pick' => ['black', 'rainbow']]])->assertStatus(422);
    }
}

function VehicleMake(CatalogTestCase $t): VehicleMake
{
    return VehicleMake::query()->where('name', 'Toyota')->firstOrFail();
}
