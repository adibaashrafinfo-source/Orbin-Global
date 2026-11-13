<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Services\Sales\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->only(['id', 'code', 'customer_type_id', 'price_tier', 'name', 'company_name', 'whatsapp', 'email', 'address_line1', 'address_line2', 'area', 'district', 'postcode',
                'nid_no', 'trade_license_no', 'bin_no', 'credit_limit', 'opening_balance', 'notes', 'is_walk_in', 'is_active', 'phone_primary']),
            'phones' => $this->phones ?? [],
            'custom' => $this->custom ?? (object) [],
            'balance' => $this->balance ?? app(CustomerService::class)->balance($this->id),
            'vehicles' => $this->whenLoaded('vehicles', fn () => $this->vehicles->map(fn ($v) => [
                'id' => $v->id, 'reg_no' => $v->reg_no, 'make_id' => $v->make_id, 'model_id' => $v->model_id, 'year' => $v->year, 'color' => $v->color, 'note' => $v->note,
                'make' => $v->make?->name, 'model' => $v->model?->name,
            ])->values()),
        ];
    }
}
