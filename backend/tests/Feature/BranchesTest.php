<?php

namespace Tests\Feature;

use Tests\TestCase;

class BranchesTest extends TestCase
{
    public function test_seeded_branch_and_warehouses(): void
    {
        $this->actingAsRole('cashier');
        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'HQ')->assertJsonPath('data.0.is_default', true);
        $res = $this->getJson('/api/v1/warehouses')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['SHOW', 'GODOWN'], collect($res->json('data'))->pluck('code')->all());
        $this->assertFalse(collect($res->json('data'))->firstWhere('code', 'GODOWN')['is_sellable']);
    }

    public function test_branch_crud_with_single_default(): void
    {
        $this->actingAsRole('super_admin');
        $res = $this->postJson('/api/v1/branches', ['code' => 'UTT', 'name' => 'Uttara Branch', 'is_default' => true, 'phone' => '01700000000']);
        $res->assertCreated()->assertJsonPath('data.is_default', true);
        $branches = $this->getJson('/api/v1/branches')->json('data');
        $this->assertSame(1, collect($branches)->where('is_default', true)->count());
        $this->postJson('/api/v1/branches', ['code' => 'UTT', 'name' => 'Dup'])->assertStatus(422);
        $this->postJson('/api/v1/branches', ['code' => 'bad code', 'name' => 'x'])->assertStatus(422);
        $this->putJson('/api/v1/branches/'.$res->json('data.id'), ['name' => 'Uttara', 'is_active' => false])->assertOk()->assertJsonPath('data.name', 'Uttara');
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'entity_table' => 'branches', 'entity_id' => $res->json('data.id')]);
    }

    public function test_warehouse_and_storage_location_rules(): void
    {
        $this->actingAsRole('super_admin');
        $wh = $this->postJson('/api/v1/warehouses', ['branch_id' => 1, 'code' => 'GD2', 'name' => 'Godown 2', 'kind' => 'godown', 'is_sellable' => false]);
        $wh->assertCreated()->assertJsonPath('data.kind', 'godown');
        $this->postJson('/api/v1/warehouses', ['branch_id' => 999, 'code' => 'X', 'name' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/warehouses', ['branch_id' => 1, 'code' => 'X', 'name' => 'x', 'kind' => 'attic'])->assertStatus(422);

        $id = $wh->json('data.id');
        $rack = $this->postJson('/api/v1/storage-locations', ['warehouse_id' => $id, 'code' => 'R1', 'name' => 'Rack 1', 'kind' => 'rack'])->assertCreated();
        $this->postJson('/api/v1/storage-locations', ['warehouse_id' => $id, 'code' => 'R1-S1', 'kind' => 'shelf', 'parent_id' => $rack->json('data.id')])->assertCreated();
        // code unique per warehouse, same code in another warehouse is fine
        $this->postJson('/api/v1/storage-locations', ['warehouse_id' => $id, 'code' => 'R1', 'kind' => 'rack'])->assertStatus(422);
        $this->postJson('/api/v1/storage-locations', ['warehouse_id' => 1, 'code' => 'R1', 'kind' => 'rack'])->assertCreated();
        // parent must belong to the same warehouse
        $this->postJson('/api/v1/storage-locations', ['warehouse_id' => 1, 'code' => 'R2', 'kind' => 'rack', 'parent_id' => $rack->json('data.id')])->assertStatus(422);
        $this->getJson('/api/v1/storage-locations?filter[warehouse_id]='.$id)->assertOk()->assertJsonCount(2, 'data');
    }
}
