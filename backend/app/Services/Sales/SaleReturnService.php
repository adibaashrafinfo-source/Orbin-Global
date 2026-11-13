<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\FinancialAccount;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Services\Inventory\StockPostingService;
use App\Services\NumberService;
use App\Services\PermissionService;
use App\Services\SettingsService;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** FR-715, BR-052: returns against an invoice, priced from what the customer actually paid for those lines. */
class SaleReturnService
{
    public function __construct(
        private readonly StockPostingService $stock,
        private readonly CustomerService $customers,
        private readonly NumberService $numbers,
        private readonly AuditService $audit,
        private readonly PeriodLockService $period,
        private readonly SettingsService $settings,
        private readonly PermissionService $permissions,
        private readonly InvoicePdfService $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $in  {items: [{sale_item_id, qty, condition, restock_warehouse_id?}], reason_id?, refund_mode, refund_method?, refund_account_id?, note?}
     */
    public function create(User $user, Sale $sale, array $in): SaleReturn
    {
        $return = DB::transaction(function () use ($user, $sale, $in): SaleReturn {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['completed', 'partially_returned'], true)) {
                throw new ApiException('INVALID_STATE', 409);
            }
            $this->period->assertOpen(now('Asia/Dhaka'));
            $canApprove = $this->permissions->userHas($user, 'sales.return.approve');
            $priced = $this->price($locked, $in['items'], $canApprove);

            $mode = (string) $in['refund_mode'];
            $paidLeft = Dec::parse((string) $locked->paid_total, Dec::MONEY) - Dec::parse((string) DB::table('sale_returns')->where('sale_id', $locked->id)->where('status', 'posted')->whereNull('deleted_at')->sum('refund_total'), Dec::MONEY);
            $total = array_sum(array_column($priced, 'line_total'));
            $refund = $mode === 'ledger_credit' ? 0 : min($total, max($paidLeft, 0));
            [$method, $accountId] = $refund > 0 ? $this->refundTarget($locked, $mode, $in) : [null, null];

            $return = SaleReturn::query()->create([
                'branch_id' => $locked->branch_id, 'return_no' => $this->numbers->next('sale_return', $locked->branch_id), 'sale_id' => $locked->id, 'customer_id' => $locked->customer_id,
                'status' => $canApprove ? 'draft' : 'pending_approval', 'return_date' => now(), 'reason_id' => $in['reason_id'] ?? null, 'refund_mode' => $mode,
                'refund_method' => $method, 'refund_account_id' => $accountId, 'subtotal' => $this->m($total - array_sum(array_column($priced, 'tax'))), 'tax_total' => $this->m(array_sum(array_column($priced, 'tax'))),
                'refund_total' => $this->m($refund), 'ledger_credit_total' => $this->m($total - $refund), 'note' => $in['note'] ?? null,
            ]);
            foreach ($priced as $p) {
                $return->items()->create([
                    'sale_item_id' => $p['item']->id, 'variant_id' => $p['item']->variant_id, 'qty' => Dec::fmt($p['qty'], Dec::QTY), 'unit_price' => $p['item']->unit_price, 'tax_amount' => $this->m($p['tax']),
                    'line_total' => $this->m($p['line_total']), 'condition' => $p['condition'], 'restock_warehouse_id' => $p['warehouse_id'],
                ]);
            }
            $this->audit->log('sale_return_create', 'sale_returns', $return->id, null, ['return_no' => $return->return_no, 'sale_id' => $locked->id, 'status' => $return->status, 'refund_total' => $return->refund_total, 'ledger_credit_total' => $return->ledger_credit_total]);

            return $return;
        }, 3);

        return $return->status === 'draft' ? $this->post($user, $return) : $return->refresh();
    }

    /** Approve and post. Re-checks quantities under lock: another return may have been posted since this one was made. */
    public function post(User $approver, SaleReturn $return): SaleReturn
    {
        $done = DB::transaction(function () use ($approver, $return): SaleReturn {
            $sale = Sale::query()->whereKey($return->sale_id)->lockForUpdate()->firstOrFail();
            $locked = SaleReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'pending_approval'], true)) {
                throw new ApiException('INVALID_STATE', 409);
            }
            if (! in_array($sale->status, ['completed', 'partially_returned'], true)) {
                throw new ApiException('INVALID_STATE', 409);
            }
            $this->period->assertOpen(now('Asia/Dhaka'));
            $when = CarbonImmutable::now();
            $day = $when->setTimezone('Asia/Dhaka')->toDateString();
            $items = SaleItem::query()->where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $stockLines = [];
            $touched = [];
            foreach ($locked->items()->get() as $ri) {
                $item = $items[$ri->sale_item_id];
                $qty = Dec::parse((string) $ri->qty, Dec::QTY);
                if ($qty > Dec::parse((string) $item->qty, Dec::QTY) - Dec::parse((string) $item->qty_returned, Dec::QTY)) {
                    throw new ApiException('QTY_EXCEEDS_SOLD', 422, '', [], ['sale_item_id' => $item->id]);
                }
                $touched[$item->id] = ($touched[$item->id] ?? 0) + $qty;
                $parts = $items->where('bundle_parent_line', $item->line_no)->all();
                $targets = $parts === [] ? [[$item, $qty]] : array_map(fn ($c) => [$c, Dec::div(Dec::mul(Dec::parse((string) $c->qty, Dec::QTY), $qty), Dec::parse((string) $item->qty, Dec::QTY))], $parts);
                foreach ($targets as [$line, $q]) {
                    $wh = (int) ($ri->restock_warehouse_id ?? $sale->warehouse_id);
                    $base = ['variant_id' => (int) $line->variant_id, 'warehouse_id' => $wh, 'unit_cost' => (string) $line->unit_cost, 'ref_table' => 'sale_returns', 'ref_id' => $locked->id, 'ref_no' => $locked->return_no, 'occurred_at' => $when];
                    $stockLines[] = $base + ['type' => 'sale_return', 'qty' => Dec::fmt($q, Dec::QTY), 'reason' => $ri->condition];
                    if ($ri->condition === 'broken') {
                        // Broken goods come back and go straight to the breakage register, so the loss shows at its cost.
                        $stockLines[] = $base + ['type' => 'breakage', 'qty' => Dec::fmt(-$q, Dec::QTY), 'reason' => 'returned_broken'];
                    }
                    if ($line->id !== $item->id) {
                        $touched[$line->id] = ($touched[$line->id] ?? 0) + $q;
                    }
                }
            }
            if ($stockLines !== []) {
                $this->stock->postMany($stockLines);
            }
            foreach ($touched as $id => $qty) {
                $items[$id]->forceFill(['qty_returned' => Dec::fmt(Dec::parse((string) $items[$id]->qty_returned, Dec::QTY) + $qty, Dec::QTY)])->save();
            }

            $total = Dec::parse((string) $locked->refund_total, Dec::MONEY) + Dec::parse((string) $locked->ledger_credit_total, Dec::MONEY);
            $refund = Dec::parse((string) $locked->refund_total, Dec::MONEY);
            $this->customers->ledger($sale->customer_id, 'return', 0, $total, 'sale_returns', $locked->id, 'Return '.$locked->return_no.' for '.$sale->invoice_no, $locked->return_no, $day, $sale->branch_id);
            if ($refund > 0) {
                // Stamped with whoever handed the cash over (the person who made the return), so it lands in their shift and not the approver's.
                AccountTransaction::query()->create([
                    'created_by' => $locked->created_by ?? $approver->id, 'branch_id' => $sale->branch_id, 'account_id' => $locked->refund_account_id, 'direction' => 'out', 'amount' => $locked->refund_total, 'txn_date' => $day, 'occurred_at' => $when,
                    'ref_type' => 'sale_refund', 'ref_id' => $locked->id, 'ref_no' => $locked->return_no, 'counterparty_type' => 'customer', 'counterparty_id' => $sale->customer_id, 'description' => 'Refund for '.$sale->invoice_no,
                ]);
                $this->customers->ledger($sale->customer_id, 'payment', $refund, 0, 'sale_returns', $locked->id, 'Refund paid for '.$locked->return_no, $locked->return_no, $day, $sale->branch_id);
            }

            $allBack = $items->filter(fn ($i) => $i->bundle_parent_line === null)->every(fn ($i) => Dec::parse((string) $i->qty_returned, Dec::QTY) >= Dec::parse((string) $i->qty, Dec::QTY));
            $sale->forceFill(['status' => $allBack ? 'returned' : 'partially_returned'])->save();
            $locked->forceFill(['status' => 'posted', 'approved_by' => $approver->id, 'approved_at' => $when])->save();
            $this->audit->log('sale_return_post', 'sale_returns', $locked->id, ['status' => 'draft'], ['status' => 'posted', 'return_no' => $locked->return_no], approverId: $approver->id);

            return $locked;
        }, 3);
        try {
            $this->pdf->ensureCreditNote($done);
        } catch (\Throwable $e) {
            Log::error('Credit note PDF failed', ['return_id' => $done->id, 'error' => $e->getMessage()]);
        }

        return $done->refresh();
    }

    public function cancel(User $user, SaleReturn $return): SaleReturn
    {
        if (! in_array($return->status, ['draft', 'pending_approval'], true)) {
            throw new ApiException('INVALID_STATE', 409);
        }
        $return->forceFill(['status' => 'cancelled'])->save();
        $this->audit->log('sale_return_cancel', 'sale_returns', $return->id, null, ['status' => 'cancelled']);

        return $return->refresh();
    }

    /**
     * Quantities, the return window and each line's share of what was paid, tax and cart discount included.
     * A line returned in full takes whatever is left of its total, so shares always add up to the line.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{item: SaleItem, qty: int, tax: int, line_total: int, condition: string, warehouse_id: int|null}>
     */
    private function price(Sale $sale, array $items, bool $canApprove): array
    {
        $out = [];
        $window = (int) $this->settings->get('default_return_window_days');
        $rows = SaleItem::query()->where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $asked = [];
        foreach ($items as $i => $row) {
            $item = $rows[(int) $row['sale_item_id']] ?? null;
            if ($item === null || $item->bundle_parent_line !== null) {
                throw new ApiException('VALIDATION', 422, '', ["items.$i.sale_item_id" => ['errors.REQUIRED']]);
            }
            $qty = Dec::parse((string) $row['qty'], Dec::QTY);
            $asked[$item->id] = ($asked[$item->id] ?? 0) + $qty;
            $remaining = Dec::parse((string) $item->qty, Dec::QTY) - Dec::parse((string) $item->qty_returned, Dec::QTY);
            if ($qty <= 0 || $asked[$item->id] > $remaining) {
                throw new ApiException('QTY_EXCEEDS_SOLD', 422, '', ["items.$i.qty" => ['errors.QTY_EXCEEDS_SOLD']], ['sale_item_id' => $item->id, 'remaining' => Dec::fmt($remaining, Dec::QTY)]);
            }
            $days = (int) (DB::table('products as p')->join('product_variants as v', 'v.product_id', '=', 'p.id')->where('v.id', $item->variant_id)->value('p.return_window_days') ?? $window);
            if ($sale->sale_date->setTimezone('Asia/Dhaka')->startOfDay()->addDays($days)->endOfDay()->isPast() && ! $canApprove) {
                throw new ApiException('WINDOW_EXPIRED', 422, '', [], ['window_days' => $days]);
            }
            $itemQty = Dec::parse((string) $item->qty, Dec::QTY);
            $earlier = Dec::parse((string) DB::table('sale_return_items as ri')->join('sale_returns as r', 'r.id', '=', 'ri.return_id')->where('ri.sale_item_id', $item->id)->where('r.status', 'posted')->whereNull('ri.deleted_at')->sum('ri.line_total'), Dec::MONEY);
            $earlierTax = Dec::parse((string) DB::table('sale_return_items as ri')->join('sale_returns as r', 'r.id', '=', 'ri.return_id')->where('ri.sale_item_id', $item->id)->where('r.status', 'posted')->whereNull('ri.deleted_at')->sum('ri.tax_amount'), Dec::MONEY);
            $lineTotal = Dec::parse((string) $item->line_total, Dec::MONEY);
            $lineTax = Dec::parse((string) $item->tax_amount, Dec::MONEY);
            $last = $asked[$item->id] === $remaining;
            $share = $last ? $lineTotal - $earlier : Dec::div(Dec::mul($lineTotal, $qty), $itemQty);
            $tax = $last ? $lineTax - $earlierTax : Dec::div(Dec::mul($lineTax, $qty), $itemQty);
            $out[] = ['item' => $item, 'qty' => $qty, 'tax' => $tax, 'line_total' => $share, 'condition' => (string) $row['condition'], 'warehouse_id' => $row['restock_warehouse_id'] ?? null];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: string, 1: int}
     */
    private function refundTarget(Sale $sale, string $mode, array $in): array
    {
        $accountId = $in['refund_account_id'] ?? null;
        $method = $in['refund_method'] ?? null;
        if ($mode === 'cash') {
            $method = 'cash';
        } elseif ($mode === 'original_method' && ! $method) {
            $biggest = $sale->payments()->orderByDesc('amount')->first();
            $method = $biggest?->method;
            $accountId ??= $biggest?->account_id;
        }
        if (! $method) {
            throw new ApiException('VALIDATION', 422, '', ['refund_method' => ['errors.REQUIRED']]);
        }
        $type = SalePayments::ACCOUNT_TYPE[$method];
        $q = FinancialAccount::query()->where(['account_type' => $type, 'is_active' => true]);
        $account = $accountId ? (clone $q)->whereKey((int) $accountId)->first() : ((clone $q)->where('is_default_for_method', true)->first() ?? (clone $q)->first());
        if ($account === null) {
            throw new ApiException('VALIDATION', 422, '', ['refund_account_id' => ['errors.ACCOUNT_REQUIRED']]);
        }

        return [$method, $account->id];
    }

    private function m(int $paisa): string
    {
        return Dec::fmt($paisa, Dec::MONEY);
    }
}
