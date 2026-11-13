<?php

namespace App\Http\Resources;

use App\Models\CashShift;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CashShift */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $open = $this->status === 'open';

        return [
            ...$this->only(['id', 'shift_no', 'cashier_id', 'cash_account_id', 'status', 'opening_float', 'expected_cash', 'counted_cash', 'variance', 'denomination_counts', 'note']),
            'opened_at' => $this->opened_at?->toIso8601String(), 'closed_at' => $this->closed_at?->toIso8601String(), 'has_z_report' => (bool) $this->z_report_path,
            'cashier' => $this->whenLoaded('cashier', fn () => $this->cashier?->full_name),
            // An open shift shows its figures live; a closed one shows what was fixed at closing.
            'totals' => $open ? app(ShiftService::class)->totals($this->resource) : $this->totals,
        ];
    }
}
