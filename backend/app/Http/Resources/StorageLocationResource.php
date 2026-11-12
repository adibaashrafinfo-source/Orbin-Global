<?php

namespace App\Http\Resources;

use App\Models\StorageLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StorageLocation */
class StorageLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'is_active' => $this->is_active,
        ];
    }
}
