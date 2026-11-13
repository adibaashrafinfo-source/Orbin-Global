<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\LookupValue;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\NumberService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FR-405. draft → in_transit → received. Sending takes stock out at the source's average cost; receiving brings it in
 * at the destination carrying that cost (BR-032). A short delivery leaves a draft adjustment at the source to decide on.
 */
class StockTransferService
{
    public function __construct(
        private readonly NumberService $numbers,
        private readonly AuditService $audit,
        private readonly StockPostingService $stock,
        private readonly PeriodLockService $period,
        private readonly NotifyService $notify,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): StockTransfer
    {
        return DB::transaction(function () use ($data): StockTransfer {
            $from = Warehouse::query()->findOrFail((int) $data['from_warehouse_id']);
            $to = Warehouse::query()->findOrFail((int) $data['to_warehouse_id']);
            if ($from->id === $to->id) {
                throw new ApiException('VALIDATION', 422, '', ['to_warehouse_id' => ['errors.SAME_WAREHOUSE']]);
            }
            $transfer = StockTransfer::query()->create([
                'branch_id' => $from->branch_id, 'transfer_no' => $this->numbers->next('transfer', $from->branch_id),
                'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'status' => 'draft', 'note' => $data['note'] ?? null,
            ]);
            $this->writeItems($transfer, $data['items']);

            return $this->fresh($transfer);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function update(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data): StockTransfer {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($transfer, ['draft']);
            $transfer->update(array_intersect_key($data, array_flip(['note'])));
            if (isset($data['items'])) {
                $this->writeItems($transfer, $data['items']);
            }

            return $this->fresh($transfer);
        }, 3);
    }

    public function send(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $t = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($t, ['draft']);
            $this->period->assertOpen(Branches::today());
            $items = $t->items()->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw new ApiException('EMPTY_RECEIPT', 422);
            }
            $lines = $items->map(fn ($i) => ['variant_id' => $i->variant_id, 'warehouse_id' => $t->from_warehouse_id, 'type' => 'transfer_out', 'qty' => Dec::fmt(-Dec::parse((string) $i->qty_sent, Dec::QTY), Dec::QTY),
                'ref_table' => 'stock_transfers', 'ref_id' => $t->id, 'ref_no' => $t->transfer_no, 'occurred_at' => now()])->all();
            $movements = $this->stock->postMany($lines);
            foreach ($items as $i => $item) {
                $item->update(['unit_cost' => $movements[$i]->unit_cost]);
            }
            $t->update(['status' => 'in_transit', 'sent_at' => now(), 'sent_by' => Auth::id()]);
            $this->audit->log('transfer_send', 'stock_transfers', $t->id, ['status' => 'draft'], ['status' => 'in_transit', 'transfer_no' => $t->transfer_no]);

            return $this->fresh($t);
        }, 3);
    }

    /**
     * @param  array<int, array{item_id?: int, variant_id?: int, qty_received: string}>|null  $received  default: everything that was sent
     */
    public function receive(StockTransfer $transfer, ?array $received = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $received): StockTransfer {
            $t = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($t, ['in_transit']);
            $this->period->assertOpen(Branches::today());
            $items = $t->items()->orderBy('id')->get();
            $lines = [];
            $short = [];
            foreach ($items as $item) {
                $sent = Dec::parse((string) $item->qty_sent, Dec::QTY);
                $given = $received === null ? $sent : $this->receivedQty($received, $item);
                if ($given < 0 || $given > $sent) {
                    throw new ApiException('RECEIVED_EXCEEDS_SENT', 422, '', [], ['variant_id' => $item->variant_id, 'sent' => $item->qty_sent]);
                }
                $item->qty_received = Dec::fmt($given, Dec::QTY);
                if ($given > 0) {
                    $lines[] = ['variant_id' => $item->variant_id, 'warehouse_id' => $t->to_warehouse_id, 'type' => 'transfer_in', 'qty' => Dec::fmt($given, Dec::QTY), 'unit_cost' => (string) $item->unit_cost,
                        'ref_table' => 'stock_transfers', 'ref_id' => $t->id, 'ref_no' => $t->transfer_no, 'occurred_at' => now()];
                }
                if ($given < $sent) {
                    $short[] = [$item, $sent - $given];
                }
            }
            $this->stock->postMany($lines);
            foreach ($items as $item) {
                $item->save();
            }
            $t->update(['status' => 'received', 'received_at' => now(), 'received_by' => Auth::id()]);
            if ($short !== []) {
                $this->draftShortageAdjustment($t, $short);
            }
            $this->audit->log('transfer_receive', 'stock_transfers', $t->id, ['status' => 'in_transit'], ['status' => 'received', 'transfer_no' => $t->transfer_no, 'short_lines' => count($short)]);

            return $this->fresh($t);
        }, 3);
    }

    /** Cancel before sending, or after sending: the goods go back to the source at their carried cost. */
    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $t = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($t, ['draft', 'in_transit']);
            if ($t->status === 'in_transit') {
                $this->period->assertOpen(Branches::today());
                $lines = $t->items()->orderBy('id')->get()->map(fn ($i) => ['variant_id' => $i->variant_id, 'warehouse_id' => $t->from_warehouse_id, 'type' => 'transfer_in', 'qty' => (string) $i->qty_sent,
                    'unit_cost' => (string) $i->unit_cost, 'ref_table' => 'stock_transfers', 'ref_id' => $t->id, 'ref_no' => $t->transfer_no, 'reason' => 'transfer cancelled', 'occurred_at' => now()])->all();
                $this->stock->postMany($lines);
            }
            $before = $t->status;
            $t->update(['status' => 'cancelled']);
            $this->audit->log('transfer_cancel', 'stock_transfers', $t->id, ['status' => $before], ['status' => 'cancelled']);

            return $this->fresh($t);
        }, 3);
    }

    public function delete(StockTransfer $transfer): void
    {
        $this->assertStatus($transfer, ['draft', 'cancelled']);
        $transfer->items()->delete();
        $transfer->delete();
    }

    /** @param  array<int, array<string, mixed>>  $received */
    private function receivedQty(array $received, StockTransferItem $item): int
    {
        foreach ($received as $r) {
            if ((isset($r['item_id']) && (int) $r['item_id'] === $item->id) || (! isset($r['item_id']) && isset($r['variant_id']) && (int) $r['variant_id'] === $item->variant_id)) {
                return Dec::parse((string) $r['qty_received'], Dec::QTY);
            }
        }

        return 0;
    }

    /** What did not arrive stays out of every warehouse until someone decides: post this draft to put it back, or reject it to write it off. */
    private function draftShortageAdjustment(StockTransfer $t, array $short): void
    {
        $reason = LookupValue::query()->whereHas('type', fn ($q) => $q->where('key', 'adjustment_reason'))->where('code', 'other')->first()
            ?? LookupValue::query()->whereHas('type', fn ($q) => $q->where('key', 'adjustment_reason'))->orderBy('id')->firstOrFail();
        $adj = StockAdjustment::query()->create([
            'branch_id' => $t->branch_id, 'adj_no' => $this->numbers->next('adjustment', $t->branch_id), 'warehouse_id' => $t->from_warehouse_id,
            'status' => 'draft', 'reason_id' => $reason->id, 'note' => 'Short delivery on transfer '.$t->transfer_no.'. Post to return the missing units to the source, or delete to write them off.',
        ]);
        $value = 0;
        foreach ($short as [$item, $qty]) {
            $cost = Dec::parse((string) $item->unit_cost, Dec::COST);
            StockAdjustmentItem::query()->create(['adjustment_id' => $adj->id, 'variant_id' => $item->variant_id, 'qty_delta' => Dec::fmt($qty, Dec::QTY), 'unit_cost' => Dec::fmt($cost, Dec::COST), 'note' => 'short on '.$t->transfer_no]);
            $value += Dec::value($qty, $cost);
        }
        $adj->update(['total_value' => Dec::fmt($value, Dec::MONEY)]);
        $this->notify->roles(['manager', 'super_admin', 'storekeeper'], 'transfer_short', 'warning', 'Transfer '.$t->transfer_no.' arrived short', 'Draft adjustment '.$adj->adj_no.' is waiting for a decision.', 'stock_adjustments', $adj->id);
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function writeItems(StockTransfer $transfer, array $items): void
    {
        $transfer->items()->forceDelete();
        foreach ($items as $row) {
            $variant = ProductVariant::query()->findOrFail((int) $row['variant_id']);
            if ($variant->is_bundle) {
                throw new ApiException('BUNDLE_NO_STOCK', 422, '', [], ['sku' => $variant->sku]);
            }
            $level = StockLevel::query()->where(['variant_id' => $variant->id, 'warehouse_id' => $transfer->from_warehouse_id])->first();
            StockTransferItem::query()->create([
                'transfer_id' => $transfer->id, 'variant_id' => $variant->id, 'qty_sent' => Dec::fmt(Dec::parse((string) $row['qty_sent'], Dec::QTY), Dec::QTY),
                'unit_cost' => $level ? (string) $level->avg_cost : '0.0000', 'note' => $row['note'] ?? null,
            ]);
        }
    }

    /** @param  array<int, string>  $from */
    private function assertStatus(StockTransfer $t, array $from): void
    {
        if (! in_array($t->status, $from, true)) {
            throw new ApiException('INVALID_STATE', 409, '', [], ['status' => $t->status]);
        }
    }

    private function fresh(StockTransfer $t): StockTransfer
    {
        return $t->refresh()->load(['fromWarehouse', 'toWarehouse', 'items.variant']);
    }
}
