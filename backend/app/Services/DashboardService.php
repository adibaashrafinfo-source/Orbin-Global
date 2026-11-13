<?php

namespace App\Services;

use App\Models\User;
use App\Reports\ReportContext;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use App\Services\Finance\AccountLedgerService;
use App\Services\Finance\DuesService;
use App\Services\Inventory\StockQueryService;
use App\Support\Branches;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard (FR-1100, PRD §8.2). Every sales, profit and collection figure comes from the same report code the report pages run,
 * so the dashboard, the report and the invoice list can never disagree (AT-017). Each widget is included only if the person may see it.
 */
class DashboardService
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly PermissionService $permissions,
        private readonly DuesService $dues,
        private readonly AccountLedgerService $accounts,
        private readonly StockQueryService $stock,
    ) {}

    /** @return array{from: string, to: string} */
    public function range(string $range, ?string $from, ?string $to): array
    {
        $today = CarbonImmutable::parse(Branches::today());

        return match ($range) {
            '7d' => ['from' => $today->subDays(6)->toDateString(), 'to' => $today->toDateString()],
            '30d' => ['from' => $today->subDays(29)->toDateString(), 'to' => $today->toDateString()],
            'month' => ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString()],
            'custom' => ['from' => $from ?: $today->toDateString(), 'to' => $to ?: $today->toDateString()],
            default => ['from' => $today->toDateString(), 'to' => $today->toDateString()],
        };
    }

    /** @return array<string, mixed> */
    public function build(User $user, string $range, ?string $from, ?string $to): array
    {
        $r = $this->range($range, $from, $to);
        $f = ReportFilters::fromInput($r);
        $ctx = ReportContext::for($user);
        $widgets = [];
        $can = fn (string $id) => ($d = $this->registry->find($id)) !== null && $this->registry->canOpen($user, $d);

        if ($can('sales-summary')) {
            $now = $this->totals('sales-summary', $f, $ctx);
            $before = $this->totals('sales-summary', $f->previous(), $ctx);
            $collect = $this->totals('sales-by-payment-method', $f, $ctx);
            $collectBefore = $this->totals('sales-by-payment-method', $f->previous(), $ctx);
            $week = ReportFilters::fromInput(['from' => CarbonImmutable::parse($f->to)->subDays(6)->toDateString(), 'to' => $f->to]);
            $trendFilters = $f->days() > 1 ? $f : $week;
            $points = $this->series($trendFilters, $ctx);
            $widgets['kpi-sales'] = ['value' => $now['total'], 'previous' => $before['total'], 'delta' => $this->delta($now['total'], $before['total']), 'spark' => $points];
            $widgets['kpi-orders'] = ['value' => $now['invoices'], 'previous' => $before['invoices'], 'delta' => $this->delta($now['invoices'], $before['invoices'], 0), 'avg_ticket' => $now['avg_ticket']];
            $widgets['kpi-collections'] = ['value' => $collect['amount'], 'previous' => $collectBefore['amount'], 'delta' => $this->delta($collect['amount'], $collectBefore['amount'])];
            $widgets['sales-trend'] = ['points' => $points, 'highlight' => Branches::today()];
            $widgets['payment-method-donut'] = ['slices' => $this->methods($f, $ctx)];
            $widgets['top-products'] = ['rows' => array_slice($this->rows('sales-by-product', $f, $ctx, ['group' => 'product']), 0, 10)];
            $widgets['recent-sales'] = ['rows' => $this->recent($f, $ctx)];
        }
        if ($can('sales-by-vehicle')) {
            $widgets['top-vehicle-models'] = ['rows' => array_slice(array_values(array_filter($this->rows('sales-by-vehicle', $f, $ctx, ['group' => 'model']), fn ($r) => $r['make'] !== 'Unknown')), 0, 10)];
        }
        if ($can('profit-by-invoice')) {
            $now = $this->totals('profit-by-invoice', $f, $ctx);
            $before = $this->totals('profit-by-invoice', $f->previous(), $ctx);
            $widgets['kpi-profit'] = ['value' => $now['profit'], 'previous' => $before['profit'], 'delta' => $this->delta($now['profit'], $before['profit']), 'margin' => $now['margin'] ?? null];
        }
        if ($this->permissions->userHas($user, 'ledger.view')) {
            $sum = $this->dues->list('customer', ['per_page' => 1])['summary'];
            $pay = $this->dues->list('supplier', ['per_page' => 1])['summary'];
            $widgets['kpi-dues'] = ['receivable' => $sum['total'], 'payable' => $pay['total'], 'customers' => $sum['parties']];
            $names = DB::table('financial_accounts')->whereNull('deleted_at')->where('is_active', true)->orderBy('account_type')->orderBy('id')->get(['id', 'name', 'account_type']);
            $bal = $this->accounts->balances();
            $widgets['account-balances'] = ['rows' => $names->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'type' => $a->account_type, 'balance' => Dec::fmt($bal[$a->id] ?? 0, Dec::MONEY)])->all()];
        }
        if ($this->permissions->userHas($user, 'inventory.view')) {
            $widgets['low-stock'] = $this->lowStock();
        }
        if ($this->permissions->userHasAny($user, ['sales.return.approve', 'inventory.adjust.approve'])) {
            $widgets['pending-approvals'] = $this->approvals();
        }

        return ['range' => $range, 'from' => $f->from, 'to' => $f->to, 'previous' => ['from' => $f->previous()->from, 'to' => $f->previous()->to], 'widgets' => $widgets];
    }

    /** @return array<string, string> */
    private function totals(string $id, ReportFilters $f, ReportContext $ctx): array
    {
        return $this->runner->totals($this->registry->find($id) ?? throw new \LogicException($id), $f, $ctx);
    }

    /** @param  array<string, mixed>  $extra @return array<int, array<string, mixed>> */
    private function rows(string $id, ReportFilters $f, ReportContext $ctx, array $extra): array
    {
        $f = new ReportFilters($f->from, $f->to, $f->branchId, $f->warehouseId, $f->asOf, $extra);

        return iterator_to_array($this->runner->stream($this->registry->find($id) ?? throw new \LogicException($id), $f, $ctx), false);
    }

    /** Day by day totals; days with no sale show as zero so the chart has no gaps. @return array<int, array{date: string, total: string, invoices: int}> */
    private function series(ReportFilters $f, ReportContext $ctx): array
    {
        $by = [];
        foreach ($this->rows('sales-summary', $f, $ctx, ['group' => 'day']) as $r) {
            $by[$r['period']] = $r;
        }
        $out = [];
        for ($d = CarbonImmutable::parse($f->from); $d->lte(CarbonImmutable::parse($f->to)); $d = $d->addDay()) {
            $k = $d->toDateString();
            $out[] = ['date' => $k, 'total' => $by[$k]['total'] ?? '0.00', 'invoices' => (int) ($by[$k]['invoices'] ?? 0)];
        }

        return $out;
    }

    /** Collections by method, accounts and sources merged. @return array<int, array{method: string, amount: string}> */
    private function methods(ReportFilters $f, ReportContext $ctx): array
    {
        $sum = [];
        foreach ($this->rows('sales-by-payment-method', $f, $ctx, []) as $r) {
            $sum[$r['method']] = ($sum[$r['method']] ?? 0) + Dec::parse($r['amount'], Dec::MONEY);
        }
        arsort($sum);

        return array_map(fn ($m, $v) => ['method' => $m, 'amount' => Dec::fmt($v, Dec::MONEY)], array_keys($sum), array_values($sum));
    }

    /** @return array<int, array<string, mixed>> */
    private function recent(ReportFilters $f, ReportContext $ctx): array
    {
        return DB::table('sales as s')->whereIn('s.status', ['completed', 'partially_returned', 'returned', 'void'])->whereNull('s.deleted_at')->when($ctx->ownOnly, fn ($q) => $q->where('s.cashier_id', $ctx->user->id))
            ->orderByDesc('s.sale_date')->orderByDesc('s.id')->limit(8)->get(['s.id', 's.invoice_no', 's.sale_date', 's.grand_total', 's.status', 's.payment_status', 's.customer_snapshot'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'invoice_no' => $r->invoice_no, 'at' => (string) $r->sale_date, 'total' => $r->grand_total, 'status' => $r->status, 'payment_status' => $r->payment_status, 'customer' => json_decode((string) $r->customer_snapshot, true)['name'] ?? ''])->all();
    }

    /** @return array<string, mixed> */
    private function lowStock(): array
    {
        $k = $this->stock->kpis(null, false);
        $rows = [];
        foreach (['out', 'low'] as $status) {
            foreach ($this->stock->overview(['status' => $status], false)->limit(8)->get() as $r) {
                $rows[] = ['variant_id' => (int) $r->variant_id, 'sku' => $r->sku, 'product' => $r->product_name, 'status' => $status, 'on_hand' => $r->on_hand];
            }
        }

        return ['low' => (int) $k['low'], 'out' => (int) $k['out'], 'rows' => array_slice($rows, 0, 8)];
    }

    /** @return array<string, int> */
    private function approvals(): array
    {
        return [
            'returns' => DB::table('sale_returns')->where('status', 'pending_approval')->whereNull('deleted_at')->count(),
            'adjustments' => DB::table('stock_adjustments')->where('status', 'pending_approval')->whereNull('deleted_at')->count(),
            'expenses' => DB::table('expenses')->where('status', 'pending_approval')->whereNull('deleted_at')->count(),
            'payments' => DB::table('sale_payments')->where('verification', 'pending')->whereNull('deleted_at')->count() + DB::table('party_payments')->where(['verification' => 'pending', 'status' => 'posted'])->whereNull('deleted_at')->count(),
        ];
    }

    /** Change against the previous period: the amount and the percent (null when there was nothing before). @return array{change: string, percent: string|null} */
    private function delta(string|int $now, string|int $before, int $scale = Dec::MONEY): array
    {
        $a = Dec::parse((string) $now, $scale);
        $b = Dec::parse((string) $before, $scale);

        return ['change' => Dec::fmt($a - $b, $scale), 'percent' => $b === 0 ? null : Dec::fmt(Dec::div(($a - $b) * 1000, abs($b)), 1)];
    }
}
