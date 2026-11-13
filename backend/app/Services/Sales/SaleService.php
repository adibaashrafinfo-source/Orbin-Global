<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\Inventory\PeriodLockService;
use App\Services\Inventory\StockPostingService;
use App\Services\NumberService;
use App\Services\PermissionService;
use App\Services\SettingsService;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FR-711: the only way a completed invoice is created. One transaction covers stock, invoice, payments, account entries and
 * the customer ledger; the server recomputes every figure and refuses a cart whose total the browser got wrong.
 */
class SaleService
{
    public function __construct(
        private readonly StockPostingService $stock,
        private readonly CustomerService $customers,
        private readonly ApprovalService $approvals,
        private readonly NumberService $numbers,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly PermissionService $permissions,
        private readonly PeriodLockService $period,
        private readonly ShiftService $shifts,
        private readonly SalePayments $paymentRules,
        private readonly InvoicePdfService $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $in  validated checkout payload (see CheckoutRequest)
     */
    public function complete(User $user, array $in): Sale
    {
        $key = (string) ($in['idempotency_key'] ?? '');
        if ($key !== '' && ($existing = $this->byKey($key)) !== null) {
            return $existing;
        }
        $company = CompanyProfile::current();
        if (! $company->invoiceReady()) {
            throw new ApiException('COMPANY_PROFILE_INCOMPLETE', 422, '', [], ['missing' => $company->missingInvoiceFields()]);
        }
        $shift = $this->shifts->current($user);
        if ($shift === null && $this->settings->get('require_open_shift')) {
            throw new ApiException('NO_OPEN_SHIFT', 422);
        }
        $when = CarbonImmutable::now();
        $this->period->assertOpen($when->setTimezone('Asia/Dhaka'));

        try {
            $sale = DB::transaction(fn (): Sale => $this->build($user, $in, $company, $shift?->id, $when), 3);
        } catch (UniqueConstraintViolationException $e) {
            // Two clicks of the same checkout: the second finds the first one's invoice.
            if ($key !== '' && ($existing = $this->byKey($key)) !== null) {
                return $existing;
            }
            if (str_contains($e->getMessage(), 'trx_key')) {
                throw new ApiException('DUPLICATE_TRXID', 409);
            }
            throw $e;
        }

        return $this->withPdf($sale);
    }

    /** @param  array<string, mixed>  $in */
    private function build(User $user, array $in, CompanyProfile $company, ?int $shiftId, CarbonImmutable $when): Sale
    {
        $customer = Customer::query()->whereKey((int) $in['customer_id'])->lockForUpdate()->first();
        if ($customer === null || ! $customer->is_active) {
            throw new ApiException('VALIDATION', 422, '', ['customer_id' => ['errors.REQUIRED']]);
        }
        $warehouse = Warehouse::query()->where(['id' => (int) $in['warehouse_id'], 'is_active' => true, 'is_sellable' => true])->first();
        if ($warehouse === null) {
            throw new ApiException('VALIDATION', 422, '', ['warehouse_id' => ['errors.REQUIRED']]);
        }

        $needs = [];
        $rows = $this->loadLines($in['lines'], $customer, $user, $needs);
        $vatMode = (string) $this->settings->get('vat_mode');
        $cart = $in['cart_discount'] ?? ['type' => 'none', 'value' => '0'];
        $calc = SalePricing::compute(
            array_map(fn ($r) => ['qty' => $r['qty'], 'unit_price' => $r['unit_price'], 'discount_type' => $r['discount_type'], 'discount_value' => $r['discount_value'], 'tax_rate' => $r['tax_rate']], $rows),
            (string) ($cart['type'] ?? 'none'), (string) ($cart['value'] ?? '0'), (string) ($in['delivery_charge'] ?? '0'), $vatMode, (string) $this->settings->get('rounding_mode'),
        );
        $grand = $calc['grand_total'];
        if (abs($grand - Dec::parse((string) ($in['client_expected_grand_total'] ?? '0'), Dec::MONEY)) > 1) {
            throw new ApiException('TOTAL_MISMATCH', 422, '', [], ['server_total' => Dec::fmt($grand, Dec::MONEY)]);
        }

        // BR-021: how much of the bill is given away, against the cashier's limit.
        $given = $calc['line_discount_total'] + $calc['cart_discount_amount'];
        $limit = (int) $this->settings->get('cashier_max_discount_percent');
        if ($calc['subtotal'] > 0 && $given * 100 > $limit * $calc['subtotal'] && ! $this->permissions->userHas($user, 'pos.discount_over_limit')) {
            $needs['discount'] = ['pos.discount_over_limit'];
        }

        $pay = $this->paymentRules->prepare($in['payments'] ?? [], $grand);
        $due = $grand - $pay['paid'];
        if ($due > 0 && $customer->is_walk_in) {
            throw new ApiException('WALKIN_DUE', 422, '', [], ['due' => Dec::fmt($due, Dec::MONEY)]);
        }
        if ($due > 0 && $this->settings->get('credit_limit_mode') === 'block' && ! $customer->is_walk_in) {
            $limitPaisa = Dec::parse((string) $customer->credit_limit, Dec::MONEY);
            $after = Dec::parse($this->customers->balance($customer->id), Dec::MONEY) + $due;
            if ($limitPaisa > 0 && $after > $limitPaisa) {
                $needs['credit'] = ['pos.discount_over_limit'];
            }
        }
        if ($pay['duplicates'] !== []) {
            $needs['duplicate_trx'] = ['payments.verify'];
        }

        // An unapproved duplicate TrxID is reported as such, not as a missing PIN.
        if ($pay['duplicates'] !== [] && trim((string) ($in['approvals']['manager_pin'] ?? '')) === '') {
            throw new ApiException('DUPLICATE_TRXID', 409);
        }
        $approver = null;
        if ($needs !== []) {
            $approver = $this->approvals->approver($user, $in['approvals']['manager_pin'] ?? null, array_values(array_unique(array_merge(...array_values($needs)))));
            foreach ($needs as $keys) {
                if (! $this->permissions->userHasAny($approver, $keys)) {
                    throw new ApiException('PIN_INVALID', 422);
                }
            }
        }

        $branchId = $warehouse->branch_id;
        $sale = Sale::query()->create([
            'branch_id' => $branchId, 'invoice_no' => $this->numbers->next('invoice', $branchId, $when->setTimezone('Asia/Dhaka')),
            'idempotency_key' => ($in['idempotency_key'] ?? '') !== '' ? (string) $in['idempotency_key'] : null, 'verify_token' => Str::random(40),
            'warehouse_id' => $warehouse->id, 'shift_id' => $shiftId, 'customer_id' => $customer->id,
            'customer_snapshot' => SaleSnapshots::customer($customer), 'company_snapshot' => SaleSnapshots::company($company),
            'vehicle_snapshot' => SaleSnapshots::vehicle($in['vehicle'] ?? null, $customer),
            'sale_channel' => $in['sale_channel'] ?? 'counter', 'status' => 'completed', 'sale_date' => $when, 'due_date' => $due > 0 ? ($in['due_date'] ?? null) : null,
            'vat_mode_snapshot' => $vatMode, 'subtotal' => $this->m($calc['subtotal']), 'line_discount_total' => $this->m($calc['line_discount_total']),
            'cart_discount_type' => (string) ($cart['type'] ?? 'none'), 'cart_discount_value' => $this->m(Dec::parse((string) ($cart['value'] ?? '0'), Dec::MONEY)),
            'cart_discount_amount' => $this->m($calc['cart_discount_amount']), 'taxable_amount' => $this->m($calc['taxable_amount']), 'tax_total' => $this->m($calc['tax_total']),
            'delivery_charge' => $this->m($calc['delivery_charge']), 'round_off' => $this->m($calc['round_off']), 'grand_total' => $this->m($grand),
            'paid_total' => $this->m($pay['paid']), 'due_total' => $this->m($due), 'change_given' => $this->m($pay['change']),
            'payment_status' => $due === 0 ? 'paid' : ($pay['paid'] === 0 ? 'unpaid' : 'partial'),
            'salesperson_id' => $in['salesperson_id'] ?? $user->id, 'cashier_id' => $user->id, 'note' => $in['note'] ?? null,
        ]);

        $this->postStockAndItems($sale, $rows, $calc, $warehouse->id, $when, $approver);
        $this->postPayments($sale, $pay['lines'], $customer, $approver, $when);
        $this->postLedger($sale, $customer, $grand, $pay['lines'], $when);

        if (! empty($in['quotation_id'])) {
            Quotation::query()->whereKey((int) $in['quotation_id'])->whereIn('status', ['draft', 'sent', 'accepted'])->update(['status' => 'converted', 'converted_sale_id' => $sale->id]);
        }
        $this->audit->log('sale_complete', 'sales', $sale->id, null, [
            'invoice_no' => $sale->invoice_no, 'grand_total' => $sale->grand_total, 'paid_total' => $sale->paid_total, 'due_total' => $sale->due_total, 'customer_id' => $customer->id,
        ], reason: $in['approvals']['reason'] ?? null, approverId: $approver?->id);

        return $sale;
    }

    /**
     * Reads every line's variant under a shared lock (so a price cannot change mid-sale) and checks price, floor and tier.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, array<int, string>>  $needs  filled with approvals the sale cannot go without
     * @return array<int, array<string, mixed>>
     */
    private function loadLines(array $lines, Customer $customer, User $user, array &$needs): array
    {
        $ids = array_values(array_unique(array_map(fn ($l) => (int) $l['variant_id'], $lines)));
        sort($ids);
        DB::table('product_variants')->whereIn('id', $ids)->orderBy('id')->sharedLock()->pluck('id');
        $variants = ProductVariant::query()->with(['product.brand', 'product.qualityGrade', 'product.taxRate', 'taxRate', 'bundleItems.component.product.brand', 'bundleItems.component.product.qualityGrade'])->whereIn('id', $ids)->get()->keyBy('id');
        $defaultRate = (string) (TaxRate::query()->where('code', $this->settings->get('default_tax_rate_code'))->value('rate_percent') ?? '0');
        $canOverride = $this->permissions->userHas($user, 'pos.price_override');
        $canFloor = $this->permissions->userHas($user, 'pos.sell_below_floor');

        $rows = [];
        foreach (array_values($lines) as $i => $line) {
            $v = $variants[(int) $line['variant_id']] ?? null;
            if ($v === null || ! $v->is_active || $v->is_discontinued || ! $v->product->is_active) {
                throw new ApiException('VARIANT_UNAVAILABLE', 422, '', ["lines.$i.variant_id" => ['errors.VARIANT_UNAVAILABLE']], ['variant_id' => (int) $line['variant_id']]);
            }
            $tier = $this->tierPrice($v, $customer->price_tier);
            $entered = (string) ($line['unit_price'] ?? $tier['price']);
            $override = null;
            if (Dec::parse($entered, Dec::MONEY) !== Dec::parse($tier['price'], Dec::MONEY)) {
                if (! $canOverride) {
                    throw ApiException::forbidden('PRICE_OVERRIDE_FORBIDDEN');
                }
                $override = $user->id;
            }
            if ($v->min_price !== null && Dec::parse($entered, Dec::MONEY) < Dec::parse((string) $v->min_price, Dec::MONEY) && ! $canFloor) {
                $needs['floor'] = ['pos.sell_below_floor'];
            }
            $rate = (string) ($v->taxRate?->rate_percent ?? $v->product->taxRate?->rate_percent ?? $defaultRate);
            $rows[$i] = [
                'variant' => $v, 'qty' => (string) $line['qty'], 'unit_price' => $entered, 'price_tier' => $tier['tier'], 'discount_type' => (string) ($line['discount_type'] ?? 'none'),
                'discount_value' => (string) ($line['discount_value'] ?? '0'), 'tax_rate' => $rate, 'note' => $line['note'] ?? null, 'override_by' => $override,
                'override_reason' => $line['override_reason'] ?? null, 'tier_price' => $tier['price'],
            ];
        }

        return $rows;
    }

    /** @return array{price: string, tier: string} BR-002: the customer's tier price, retail when that tier has none. */
    private function tierPrice(ProductVariant $v, string $tier): array
    {
        $price = match ($tier) {
            'wholesale' => $v->wholesale_price, 'dealer' => $v->dealer_price, default => $v->retail_price,
        };
        if ($price === null) {
            return ['price' => (string) $v->retail_price, 'tier' => 'retail'];
        }

        return ['price' => (string) $price, 'tier' => $tier];
    }

    /**
     * Stock first (it takes the row locks and may refuse), then the item rows with the cost each movement actually used (BR-031).
     * A bundle becomes its own priced line plus one zero-priced row per component carrying that component's cost (BR-033).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $calc
     */
    private function postStockAndItems(Sale $sale, array $rows, array $calc, int $warehouseId, CarbonImmutable $when, ?User $approver): void
    {
        $plan = [];
        $stockLines = [];
        foreach ($rows as $i => $r) {
            /** @var ProductVariant $v */
            $v = $r['variant'];
            $plan[$i] = ['components' => []];
            if ($v->is_bundle) {
                if ($v->bundleItems->isEmpty()) {
                    throw new ApiException('BUNDLE_EMPTY', 422, '', [], ['variant_id' => $v->id, 'sku' => $v->sku]);
                }
                foreach ($v->bundleItems as $b) {
                    $qty = Dec::rescale(Dec::mul(Dec::parse((string) $b->qty, Dec::QTY), Dec::parse($r['qty'], Dec::QTY)), Dec::QTY * 2, Dec::QTY);
                    $plan[$i]['components'][] = ['variant' => $b->component, 'qty' => $qty, 'key' => count($stockLines)];
                    $stockLines[] = $this->stockLine($b->component->id, $warehouseId, $qty, $sale, $when);
                }
            } else {
                $plan[$i]['key'] = count($stockLines);
                $stockLines[] = $this->stockLine($v->id, $warehouseId, Dec::parse($r['qty'], Dec::QTY), $sale, $when);
            }
        }
        $movements = $this->stock->postMany($stockLines);

        $lineNo = 0;
        foreach ($rows as $i => $r) {
            /** @var ProductVariant $v */
            $v = $r['variant'];
            $c = $calc['lines'][$i];
            $months = (int) $v->product->warranty_months;
            $parentLine = ++$lineNo;
            $cost = $v->is_bundle ? 0 : Dec::parse((string) $movements[$plan[$i]['key']]->unit_cost, Dec::COST);
            $sale->items()->create([
                'line_no' => $parentLine, 'variant_id' => $v->id, 'product_name_snapshot' => $v->product->name_en, 'sku_snapshot' => $v->sku, 'spec_snapshot' => SaleSnapshots::spec($v),
                'qty' => Dec::fmt($c['qty'], Dec::QTY), 'unit_price' => $this->m($c['unit_price']), 'price_tier_used' => $r['price_tier'],
                'discount_type' => $r['discount_type'], 'discount_value' => $this->m(Dec::parse($r['discount_value'], Dec::MONEY)), 'discount_amount' => $this->m($c['line_discount']),
                'cart_discount_share' => $this->m($c['cart_share']), 'tax_rate_percent' => $r['tax_rate'], 'tax_amount' => $this->m($c['tax']), 'line_total' => $this->m($c['line_total']),
                'unit_cost' => Dec::fmt($cost, Dec::COST), 'warranty_months' => $months,
                'warranty_until' => $months > 0 ? $when->setTimezone('Asia/Dhaka')->addMonths($months)->toDateString() : null,
                'note' => $r['note'], 'price_override_by' => $r['override_by'] ?? ($approver && $r['unit_price'] !== $r['tier_price'] ? $approver->id : null), 'price_override_reason' => $r['override_reason'],
            ]);
            foreach ($plan[$i]['components'] as $comp) {
                $mv = $movements[$comp['key']];
                $sale->items()->create([
                    'line_no' => ++$lineNo, 'variant_id' => $comp['variant']->id, 'product_name_snapshot' => $comp['variant']->product->name_en, 'sku_snapshot' => $comp['variant']->sku,
                    'spec_snapshot' => SaleSnapshots::spec($comp['variant']), 'qty' => Dec::fmt($comp['qty'], Dec::QTY), 'unit_price' => '0.00',
                    'price_tier_used' => $r['price_tier'], 'tax_rate_percent' => '0.00', 'tax_amount' => '0.00', 'line_total' => '0.00', 'unit_cost' => $mv->unit_cost,
                    'bundle_parent_line' => $parentLine,
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function stockLine(int $variantId, int $warehouseId, int $qtyMilli, Sale $sale, CarbonImmutable $when): array
    {
        return ['variant_id' => $variantId, 'warehouse_id' => $warehouseId, 'type' => 'sale', 'qty' => Dec::fmt(-$qtyMilli, Dec::QTY), 'ref_table' => 'sales', 'ref_id' => $sale->id, 'ref_no' => $sale->invoice_no, 'occurred_at' => $when];
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function postPayments(Sale $sale, array $lines, Customer $customer, ?User $approver, CarbonImmutable $when): void
    {
        foreach ($lines as $l) {
            $p = SalePayment::query()->create([
                'sale_id' => $sale->id, 'method' => $l['method'], 'amount' => $this->m($l['amount']), 'tendered' => $l['tendered'] !== null ? $this->m($l['tendered']) : null,
                'change_amount' => $this->m($l['change']), 'account_id' => $l['account_id'], 'sender_number' => $l['sender_number'], 'trx_id' => $l['trx_id'], 'bank_name' => $l['bank_name'],
                'bank_account_no' => $l['bank_account_no'], 'bank_ref' => $l['bank_ref'], 'transfer_date' => $l['transfer_date'], 'attachment_id' => $l['attachment_id'],
                'verification' => $l['verification'], 'duplicate_override_by' => $l['duplicate_override'] ? $approver?->id : null, 'received_at' => $when, 'trx_key' => $l['trx_key'],
            ]);
            AccountTransaction::query()->create([
                'branch_id' => $sale->branch_id, 'account_id' => $l['account_id'], 'direction' => 'in', 'amount' => $this->m($l['amount']), 'txn_date' => $when->setTimezone('Asia/Dhaka')->toDateString(),
                'occurred_at' => $when, 'ref_type' => 'sale_payment', 'ref_id' => $p->id, 'ref_no' => $sale->invoice_no, 'counterparty_type' => 'customer', 'counterparty_id' => $customer->id,
                'description' => 'Payment for '.$sale->invoice_no,
            ]);
        }
    }

    /** @param  array<int, array<string, mixed>>  $payLines */
    private function postLedger(Sale $sale, Customer $customer, int $grand, array $payLines, CarbonImmutable $when): void
    {
        $day = $when->setTimezone('Asia/Dhaka')->toDateString();
        $this->customers->ledger($customer->id, 'invoice', $grand, 0, 'sales', $sale->id, 'Invoice '.$sale->invoice_no, $sale->invoice_no, $day, $sale->branch_id);
        foreach ($payLines as $l) {
            $this->customers->ledger($customer->id, 'payment', 0, $l['amount'], 'sales', $sale->id, 'Payment ('.$l['method'].') for '.$sale->invoice_no, $sale->invoice_no, $day, $sale->branch_id);
        }
    }

    /** FR-707: park a cart on the server. No number, no stock movement, no snapshot of money; it survives a reload. */
    public function hold(User $user, array $in): Sale
    {
        $customer = Customer::query()->findOrFail((int) ($in['customer_id'] ?? Customer::query()->where('is_walk_in', true)->value('id')));
        $warehouseId = (int) ($in['warehouse_id'] ?? Warehouse::query()->where('is_default', true)->value('id'));
        $sale = Sale::query()->create([
            'branch_id' => Warehouse::query()->findOrFail($warehouseId)->branch_id, 'warehouse_id' => $warehouseId, 'customer_id' => $customer->id,
            'customer_snapshot' => SaleSnapshots::customer($customer), 'company_snapshot' => [], 'status' => 'held', 'sale_date' => now(),
            'vat_mode_snapshot' => (string) $this->settings->get('vat_mode'), 'cashier_id' => $user->id,
            'held_name' => trim((string) ($in['held_name'] ?? '')) ?: $customer->name.' '.now('Asia/Dhaka')->format('H:i'), 'held_payload' => $in['payload'] ?? [],
        ]);
        $this->audit->log('sale_hold', 'sales', $sale->id, null, ['held_name' => $sale->held_name]);

        return $sale;
    }

    /** A held bill is used once: resuming it hands the cart back and removes the parked row. */
    public function resume(Sale $held): array
    {
        if ($held->status !== 'held' || $held->trashed()) {
            throw new ApiException('INVALID_STATE', 409);
        }
        $payload = $held->held_payload ?? [];
        $held->delete();

        return $payload;
    }

    /**
     * BR-051: void a completed invoice. Reverses stock at the cost it left at, the payments, and the ledger; the invoice stays
     * visible as VOID. A sale that already has a return cannot be voided (the return is the correction).
     */
    public function void(User $user, Sale $sale, string $reason): Sale
    {
        $isSuper = $user->isSuperAdmin();
        $saleDay = $sale->sale_date->setTimezone('Asia/Dhaka')->toDateString();
        if (! $isSuper && $this->settings->get('void_same_day_manager_only') && $saleDay !== Branches::today()) {
            throw ApiException::forbidden();
        }
        $this->period->assertOpen($sale->sale_date->setTimezone('Asia/Dhaka'));

        DB::transaction(function () use ($user, $sale, $reason): void {
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'void') {
                throw new ApiException('ALREADY_VOID', 409);
            }
            if (in_array($locked->status, ['partially_returned', 'returned'], true) || DB::table('sale_returns')->where('sale_id', $locked->id)->whereNull('deleted_at')->where('status', '<>', 'cancelled')->exists()) {
                throw new ApiException('HAS_RETURNS', 422);
            }
            if ($locked->status !== 'completed') {
                throw new ApiException('INVALID_STATE', 409);
            }
            $when = CarbonImmutable::now();
            $day = $when->setTimezone('Asia/Dhaka')->toDateString();

            // One reversal per original movement, at the cost it left at; a bundle's own row has none, its components do.
            $lines = [];
            foreach (DB::table('stock_movements')->where(['ref_table' => 'sales', 'ref_id' => $locked->id, 'movement_type' => 'sale'])->orderBy('id')->get() as $movement) {
                $lines[] = ['variant_id' => (int) $movement->variant_id, 'warehouse_id' => (int) $movement->warehouse_id, 'type' => 'sale_return', 'qty' => Dec::fmt(abs(Dec::parse((string) $movement->qty_delta, Dec::QTY)), Dec::QTY),
                    'unit_cost' => (string) $movement->unit_cost, 'ref_table' => 'sales', 'ref_id' => $locked->id, 'ref_no' => $locked->invoice_no, 'reason' => 'void', 'occurred_at' => $when];
            }
            if ($lines !== []) {
                $this->stock->postMany($lines);
            }
            foreach ($locked->payments()->get() as $p) {
                AccountTransaction::query()->create([
                    'branch_id' => $locked->branch_id, 'account_id' => $p->account_id, 'direction' => 'out', 'amount' => $p->amount, 'txn_date' => $day, 'occurred_at' => $when,
                    'ref_type' => 'adjustment', 'ref_id' => $p->id, 'ref_no' => $locked->invoice_no, 'counterparty_type' => 'customer', 'counterparty_id' => $locked->customer_id, 'description' => 'Void '.$locked->invoice_no,
                ]);
                $this->customers->ledger($locked->customer_id, 'adjustment', Dec::parse((string) $p->amount, Dec::MONEY), 0, 'sales', $locked->id, 'Void: payment reversed for '.$locked->invoice_no, $locked->invoice_no, $day, $locked->branch_id);
            }
            $this->customers->ledger($locked->customer_id, 'adjustment', 0, Dec::parse((string) $locked->grand_total, Dec::MONEY), 'sales', $locked->id, 'Void '.$locked->invoice_no, $locked->invoice_no, $day, $locked->branch_id);
            $locked->forceFill(['status' => 'void', 'void_reason' => $reason, 'voided_by' => $user->id, 'voided_at' => $when])->save();
            $this->audit->log('sale_void', 'sales', $locked->id, ['status' => 'completed'], ['status' => 'void', 'invoice_no' => $locked->invoice_no, 'grand_total' => $locked->grand_total], $reason);
        }, 3);

        return $this->withPdf($sale->refresh(), true);
    }

    private function byKey(string $key): ?Sale
    {
        $sale = Sale::query()->where('idempotency_key', $key)->first();

        return $sale === null ? null : $this->withPdf($sale);
    }

    /** The PDF is made after the sale is safely committed; if it fails the sale stands and the PDF is retried on download. */
    private function withPdf(Sale $sale, bool $force = false): Sale
    {
        try {
            $this->pdf->ensure($sale, $force);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Invoice PDF failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        return $sale->refresh();
    }

    private function m(int $paisa): string
    {
        return Dec::fmt($paisa, Dec::MONEY);
    }
}
