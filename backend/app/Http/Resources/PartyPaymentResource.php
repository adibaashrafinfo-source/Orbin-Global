<?php

namespace App\Http\Resources;

use App\Models\PartyPayment;
use App\Support\Dec;
use App\Support\PartyDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin PartyPayment */
class PartyPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $party = PartyDirectory::find($this->party_type, (int) $this->party_id);

        return [
            ...$this->only(['id', 'payment_no', 'party_type', 'party_id', 'direction', 'method', 'amount', 'account_id', 'sender_number', 'trx_id', 'bank_name', 'bank_ref',
                'allocated_total', 'advance_amount', 'verification', 'note', 'status', 'void_reason', 'created_by']),
            'payment_date' => $this->payment_date?->toDateString(),
            'party' => $party ? ['id' => $party->id, 'code' => $party->code, 'name' => $party->name] : null,
            'account' => $this->whenLoaded('account', fn () => ['id' => $this->account->id, 'name' => $this->account->name, 'account_type' => $this->account->account_type]),
            'has_receipt' => $this->receipt_pdf_path !== null,
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'doc_table' => $a->doc_table, 'doc_id' => (int) $a->doc_id, 'doc_no' => $this->docNo($a->doc_table, (int) $a->doc_id), 'amount' => Dec::fmt(Dec::parse((string) $a->amount, Dec::MONEY), Dec::MONEY),
            ])->values()),
        ];
    }

    private function docNo(string $table, int $id): ?string
    {
        return match ($table) {
            'sales' => DB::table('sales')->where('id', $id)->value('invoice_no'),
            'goods_receipts' => DB::table('goods_receipts')->where('id', $id)->value('grn_no'),
            default => 'OPENING',
        };
    }
}
