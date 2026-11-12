<?php

namespace App\Http\Resources;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Warehouse */
class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'is_default' => $this->is_default,
            'is_sellable' => $this->is_sellable,
            'is_active' => $this->is_active,
            'locations_count' => $this->whenCounted('locations'),
        ];
    }
}
