<?php

namespace App\Http\Resources;

use App\Models\FundTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FundTransfer */
class FundTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->only(['id', 'transfer_no', 'from_account_id', 'to_account_id', 'amount', 'fee_amount', 'fee_expense_id', 'note', 'status', 'void_reason', 'created_by']),
            'transfer_date' => $this->transfer_date?->toDateString(),
            'from' => $this->whenLoaded('from', fn () => ['id' => $this->from->id, 'name' => $this->from->name, 'account_type' => $this->from->account_type]),
            'to' => $this->whenLoaded('to', fn () => ['id' => $this->to->id, 'name' => $this->to->name, 'account_type' => $this->to->account_type]),
        ];
    }
}
