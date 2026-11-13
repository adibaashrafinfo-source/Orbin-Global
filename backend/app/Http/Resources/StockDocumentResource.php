<?php

namespace App\Http\Resources;

use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Adjustments, transfers and counts in one shape. Cost columns are left out for users without products.view_cost (AT-019),
 * and a blind count hides the system quantities until counting is over.
 */
class StockDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canCost = $user && app(PermissionService::class)->userHas($user, 'products.view_cost');
        $data = $this->resource->attributesToArray();
        unset($data['created_by'], $data['updated_by'], $data['deleted_at']);
        $blind = ($this->resource->is_blind ?? false) && $this->resource->status === 'counting';
        if (! $canCost) {
            unset($data['total_value']);
        }
        foreach (['warehouse' => 'warehouse', 'fromWarehouse' => 'from_warehouse', 'toWarehouse' => 'to_warehouse'] as $rel => $key) {
            if ($this->resource->relationLoaded($rel)) {
                $data[$key] = $this->resource->{$rel}?->only(['id', 'code', 'name']);
            }
        }
        if ($this->resource->relationLoaded('reason')) {
            $data['reason'] = $this->reason?->only(['id', 'code', 'label_en', 'label_bn']);
        }
        if ($this->resource->relationLoaded('items')) {
            $data['items'] = $this->items->map(function ($item) use ($canCost, $blind) {
                $row = $item->attributesToArray();
                unset($row['created_by'], $row['updated_by'], $row['deleted_at']);
                if (! $canCost) {
                    unset($row['unit_cost']);
                }
                if (array_key_exists('system_qty', $row)) {
                    if ($blind) {
                        unset($row['system_qty']);
                    } elseif ($row['counted_qty'] !== null) {
                        $row['variance'] = number_format((float) $row['counted_qty'] - (float) $row['system_qty'], 3, '.', '');
                    }
                }
                if ($item->relationLoaded('variant') && $item->variant) {
                    $row['sku'] = $item->variant->sku;
                    $row['variant_name'] = $item->variant->variant_name;
                }

                return $row;
            })->values();
        }

        return $data;
    }
}
