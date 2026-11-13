<?php

namespace App\Services\Inventory;

use App\Services\SettingsService;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Re-proves the books from first principles (FR-113): stock on file against the append-only movements, costs against a
 * replay, receipts against the supplier ledger, order progress against the receipts. A fail means data drifted; the
 * samples say where. It never fixes anything.
 */
class IntegrityService
{
    private const CACHE_KEY = 'orbin.integrity.last';

    private const SAMPLE = 10;

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array{status: string, ran_at: string, checks: array<int, array{key: string, status: string, count: int, samples: array<int, array<string, mixed>>}>} */
    public function run(): array
    {
        $checks = [
            $this->check('stock_levels_match_movements', $this->levelDrift()),
            $this->check('movement_balance_chain', $this->balanceChain()),
            $this->check('average_cost_matches_replay', $this->costReplay()),
            $this->negativeStock(),
            $this->check('receipt_totals', $this->receiptTotals()),
            $this->check('supplier_ledger_matches_documents', $this->ledgerVsDocuments()),
            $this->check('order_progress_matches_receipts', $this->orderProgress()),
        ];
        $status = collect($checks)->contains('status', 'fail') ? 'fail' : (collect($checks)->contains('status', 'warn') ? 'warn' : 'ok');
        $result = ['status' => $status, 'ran_at' => CarbonImmutable::now()->toIso8601String(), 'checks' => $checks];
        Cache::forever(self::CACHE_KEY, $result);

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        $v = Cache::get(self::CACHE_KEY);

        return is_array($v) ? $v : null;
    }

    /** @param  array<int, array<string, mixed>>  $bad */
    private function check(string $key, array $bad): array
    {
        return ['key' => $key, 'status' => $bad === [] ? 'ok' : 'fail', 'count' => count($bad), 'samples' => array_slice($bad, 0, self::SAMPLE)];
    }

    /** @return array<int, array<string, mixed>> */
    private function levelDrift(): array
    {
        return DB::table('stock_levels as l')->leftJoin('stock_movements as m', fn ($j) => $j->on('m.variant_id', '=', 'l.variant_id')->on('m.warehouse_id', '=', 'l.warehouse_id'))
            ->whereNull('l.deleted_at')->groupBy('l.id', 'l.variant_id', 'l.warehouse_id', 'l.qty_on_hand')->havingRaw('l.qty_on_hand <> COALESCE(SUM(m.qty_delta), 0)')
            ->get(['l.variant_id', 'l.warehouse_id', 'l.qty_on_hand', DB::raw('COALESCE(SUM(m.qty_delta), 0) as movements_sum')])->map(fn ($r) => (array) $r)->all();
    }

    /** Every movement's balance_after must equal the running total of the movements before it. @return array<int, array<string, mixed>> */
    private function balanceChain(): array
    {
        return DB::table(DB::raw('(SELECT id, variant_id, warehouse_id, balance_after, SUM(qty_delta) OVER (PARTITION BY variant_id, warehouse_id ORDER BY id) AS running FROM stock_movements) t'))
            ->whereColumn('balance_after', '<>', 'running')->limit(self::SAMPLE + 1)->get(['id', 'variant_id', 'warehouse_id', 'balance_after', 'running'])->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function costReplay(): array
    {
        $bad = [];
        $state = null;
        $compare = function () use (&$state, &$bad): void {
            if (! $state) {
                return;
            }
            $level = DB::table('stock_levels')->where(['variant_id' => $state['v'], 'warehouse_id' => $state['w']])->first(['avg_cost']);
            $expected = Dec::fmt($state['avg'], Dec::COST);
            // An empty shelf's average is irrelevant: the next receipt overwrites it.
            if ($state['qty'] > 0 && $level && (string) $level->avg_cost !== $expected) {
                $bad[] = ['variant_id' => $state['v'], 'warehouse_id' => $state['w'], 'stored' => (string) $level->avg_cost, 'replayed' => $expected];
            }
        };
        foreach (DB::table('stock_movements')->orderBy('variant_id')->orderBy('warehouse_id')->orderBy('id')->select(['variant_id', 'warehouse_id', 'movement_type', 'qty_delta', 'unit_cost', 'reason'])->cursor() as $m) {
            if (! $state || $state['v'] !== (int) $m->variant_id || $state['w'] !== (int) $m->warehouse_id) {
                $compare();
                $state = ['v' => (int) $m->variant_id, 'w' => (int) $m->warehouse_id, 'qty' => 0, 'avg' => 0];
            }
            $delta = Dec::parse((string) $m->qty_delta, Dec::QTY);
            [, $avg] = StockPostingService::costRule($m->movement_type, $delta, $state['qty'], $state['avg'], Dec::parse((string) $m->unit_cost, Dec::COST), 0, $m->movement_type === 'purchase_receipt' && $m->reason === StockPostingService::ARRIVAL_DAMAGE);
            $state['qty'] += $delta;
            $state['avg'] = $avg;
        }
        $compare();

        return $bad;
    }

    private function negativeStock(): array
    {
        $rows = DB::table('stock_levels')->whereNull('deleted_at')->where('qty_on_hand', '<', 0)->get(['variant_id', 'warehouse_id', 'qty_on_hand'])->map(fn ($r) => (array) $r)->all();
        $allowed = (bool) $this->settings->get('allow_negative_stock');

        return ['key' => 'no_negative_stock', 'status' => $rows === [] ? 'ok' : ($allowed ? 'warn' : 'fail'), 'count' => count($rows), 'samples' => array_slice($rows, 0, self::SAMPLE)];
    }

    /** @return array<int, array<string, mixed>> */
    private function receiptTotals(): array
    {
        return DB::table('goods_receipts as g')->leftJoin('goods_receipt_items as i', fn ($j) => $j->on('i.grn_id', '=', 'g.id')->whereNull('i.deleted_at'))
            ->leftJoin(DB::raw('(SELECT grn_id, SUM(amount) as extras FROM purchase_extra_costs WHERE deleted_at IS NULL GROUP BY grn_id) e'), 'e.grn_id', '=', 'g.id')
            ->where('g.status', 'posted')->whereNull('g.deleted_at')->groupBy('g.id', 'g.grn_no', 'g.items_total', 'g.extra_cost_total', 'g.grand_total', 'e.extras')
            ->havingRaw('g.items_total <> COALESCE(SUM(i.line_total), 0) OR g.extra_cost_total <> COALESCE(e.extras, 0) OR g.grand_total <> g.items_total + g.extra_cost_total')
            ->get(['g.id', 'g.grn_no', 'g.items_total', 'g.extra_cost_total', 'g.grand_total'])->map(fn ($r) => (array) $r)->all();
    }

    /** Each posted receipt owes the supplier items + billed extras once; each posted return takes its total back. @return array<int, array<string, mixed>> */
    private function ledgerVsDocuments(): array
    {
        $bad = [];
        $receipts = DB::table('goods_receipts as g')->where('g.status', 'posted')->whereNull('g.deleted_at')
            ->selectRaw("g.id, g.grn_no, g.items_total + COALESCE((SELECT SUM(amount) FROM purchase_extra_costs x WHERE x.grn_id = g.id AND x.deleted_at IS NULL AND (x.paid_to IS NULL OR x.paid_to = '')), 0) as owed,
                COALESCE((SELECT SUM(credit - debit) FROM party_ledger_entries l WHERE l.ref_table = 'goods_receipts' AND l.ref_id = g.id AND l.party_type = 'supplier'), 0) as booked")->get();
        foreach ($receipts as $r) {
            if (Dec::parse((string) $r->owed, Dec::MONEY) !== Dec::parse((string) $r->booked, Dec::MONEY)) {
                $bad[] = ['document' => $r->grn_no, 'should_owe' => (string) $r->owed, 'ledger' => (string) $r->booked];
            }
        }
        $returns = DB::table('purchase_returns as p')->where('p.status', 'posted')->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.return_no, p.total as expected, COALESCE((SELECT SUM(debit - credit) FROM party_ledger_entries l WHERE l.ref_table = 'purchase_returns' AND l.ref_id = p.id AND l.party_type = 'supplier'), 0) as booked")->get();
        foreach ($returns as $r) {
            if (Dec::parse((string) $r->expected, Dec::MONEY) !== Dec::parse((string) $r->booked, Dec::MONEY)) {
                $bad[] = ['document' => $r->return_no, 'expected' => (string) $r->expected, 'ledger' => (string) $r->booked];
            }
        }

        return $bad;
    }

    /** @return array<int, array<string, mixed>> */
    private function orderProgress(): array
    {
        $inner = DB::table('purchase_order_items as p')->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.po_id, p.qty_received, COALESCE((SELECT SUM(i.qty_received) FROM goods_receipt_items i JOIN goods_receipts g ON g.id = i.grn_id WHERE i.po_item_id = p.id AND g.status = 'posted' AND i.deleted_at IS NULL AND g.deleted_at IS NULL), 0) as receipts");

        return DB::query()->fromSub($inner, 't')->whereColumn('qty_received', '<>', 'receipts')->get()->map(fn ($r) => (array) $r)->all();
    }
}
