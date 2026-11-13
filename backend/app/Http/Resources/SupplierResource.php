<?php

namespace App\Http\Resources;

use App\Models\Supplier;
use App\Services\Purchasing\SupplierService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->only(['id', 'code', 'name', 'contact_person', 'phones', 'email', 'address', 'city', 'country', 'supplier_type', 'payment_terms_days', 'credit_limit', 'opening_balance', 'bank_details', 'notes', 'custom', 'is_active']),
            'phones' => $this->phones ?? [],
            'balance' => $this->balance ?? app(SupplierService::class)->balance($this->id),
        ];
    }
}
