<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\CashShift;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NumberService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** FR-716: open with a float, record cash in and out, close against a count, and keep the Z-report figures. */
class ShiftService
{
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

    public function __construct(private readonly NumberService $numbers, private readonly AuditService $audit) {}

    public function current(User $cashier): ?CashShift
    {
        return CashShift::query()->where(['cashier_id' => $cashier->id, 'status' => 'open'])->first();
    }

    public function open(User $cashier, int $cashAccountId, string $openingFloat): CashShift
    {
        $float = Dec::parse($openingFloat === '' ? '0' : $openingFloat, Dec::MONEY);
        $account = FinancialAccount::query()->where(['id' => $cashAccountId, 'account_type' => 'cash', 'is_active' => true])->first();
        if ($account === null || $float < 0) {
            throw new ApiException('VALIDATION', 422, '', ['cash_account_id' => ['errors.REQUIRED']]);
        }
        try {
            return DB::transaction(function () use ($cashier, $account, $float): CashShift {
                $branch = Branches::currentId();
                $shift = CashShift::query()->create([
                    'branch_id' => $branch, 'shift_no' => $this->numbers->next('shift', $branch), 'cashier_id' => $cashier->id,
                    'cash_account_id' => $account->id, 'status' => 'open', 'opened_at' => now(), 'opening_float' => Dec::fmt($float, Dec::MONEY),
                    'open_key' => $cashier->id.':'.$account->id,
                ]);
                if ($float > 0) {
                    $this->accountEntry($shift, 'in', $float, 'Opening float');
                }
                $this->audit->log('shift_open', 'cash_shifts', $shift->id, null, ['shift_no' => $shift->shift_no, 'opening_float' => $shift->opening_float]);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('SHIFT_ALREADY_OPEN', 409);
        }
    }

    /** A cashier holds one open shift at a time, whichever drawer it is (spec: one open shift per cashier per cash account). */
    public function assertNoOpen(User $cashier): void
    {
        if ($this->current($cashier) !== null) {
            throw new ApiException('SHIFT_ALREADY_OPEN', 409);
        }
    }

    /** Cash put into or taken out of the drawer during the shift; a reason is required. */
    public function cashMovement(CashShift $shift, string $direction, string $amount, string $reason): void
    {
        if ($shift->status !== 'open') {
            throw new ApiException('ALREADY_CLOSED', 409);
        }
        $paisa = Dec::parse($amount, Dec::MONEY);
        if ($paisa <= 0 || trim($reason) === '') {
            throw new ApiException('VALIDATION', 422, '', ['reason' => ['errors.REQUIRED']]);
        }
        DB::transaction(function () use ($shift, $direction, $paisa, $reason): void {
            $locked = CashShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw new ApiException('ALREADY_CLOSED', 409);
            }
            if ($direction === 'out' && $this->expectedCash($locked) < $paisa) {
                throw new ApiException('INSUFFICIENT_CASH', 422);
            }
            $this->accountEntry($locked, $direction, $paisa, $reason);
            $this->audit->log('shift_cash_'.$direction, 'cash_shifts', $locked->id, null, ['amount' => Dec::fmt($paisa, Dec::MONEY)], $reason);
        });
    }

    /** @param  array<int|string, int|string>  $counts denomination => number of notes or coins */
    public function close(CashShift $shift, array $counts, ?string $note): CashShift
    {
        return DB::transaction(function () use ($shift, $counts, $note): CashShift {
            $locked = CashShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw new ApiException('ALREADY_CLOSED', 409);
            }
            $clean = [];
            $counted = 0;
            foreach (self::DENOMINATIONS as $d) {
                $n = (int) ($counts[$d] ?? $counts[(string) $d] ?? 0);
                if ($n < 0) {
                    throw new ApiException('VALIDATION', 422, '', ['denomination_counts' => ['errors.REQUIRED']]);
                }
                $clean[$d] = $n;
                $counted += $d * $n * 100;
            }
            $totals = $this->totals($locked);
            $expected = $this->expectedCash($locked);
            $locked->forceFill([
                'status' => 'closed', 'closed_at' => now(), 'open_key' => null, 'denomination_counts' => $clean,
                'counted_cash' => Dec::fmt($counted, Dec::MONEY), 'expected_cash' => Dec::fmt($expected, Dec::MONEY),
                'variance' => Dec::fmt($counted - $expected, Dec::MONEY), 'totals' => $totals, 'note' => $note,
            ])->save();
            $this->audit->log('shift_close', 'cash_shifts', $locked->id, ['status' => 'open'], ['expected' => $locked->expected_cash, 'counted' => $locked->counted_cash, 'variance' => $locked->variance], $note);

            return $locked->refresh();
        });
    }

    /** Cash expected in the drawer now, in paisa: float + cash sales − cash refunds + cash in − cash out. */
    public function expectedCash(CashShift $shift): int
    {
        $t = $this->totals($shift);

        return Dec::parse($t['expected_cash'], Dec::MONEY);
    }

    /**
     * Z-report figures. Cash is read from the drawer's account transactions between opening and now, by this cashier, so a
     * sale, a cash refund and a cash in or out are all counted the same way the books count them.
     *
     * @return array<string, mixed>
     */
    public function totals(CashShift $shift): array
    {
        $until = $shift->closed_at ?? now();
        $byMethod = DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.shift_id', $shift->id)->whereNull('p.deleted_at')->whereNull('s.deleted_at')->where('s.status', '<>', 'void')
            ->groupBy('p.method')->selectRaw('p.method, COUNT(*) as n, SUM(p.amount) as total')->get();
        $methods = [];
        foreach ($byMethod as $row) {
            $methods[$row->method] = ['count' => (int) $row->n, 'total' => Dec::fmt(Dec::parse((string) $row->total, Dec::MONEY), Dec::MONEY)];
        }
        $voided = DB::table('sales')->where('shift_id', $shift->id)->where('status', 'void')->whereNull('deleted_at')->selectRaw('COUNT(*) as n, COALESCE(SUM(grand_total), 0) as total')->first();
        $sales = DB::table('sales')->where('shift_id', $shift->id)->where('status', '<>', 'void')->whereNull('deleted_at')->selectRaw('COUNT(*) as n, COALESCE(SUM(grand_total), 0) as total')->first();

        $float = Dec::parse((string) $shift->opening_float, Dec::MONEY);
        // Cash sales come from the payment lines of this shift's sales that still stand; a voided sale drops out whole.
        $cashSales = Dec::parse((string) DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.shift_id', $shift->id)->where('p.method', 'cash')->where('s.status', '<>', 'void')->whereNull('p.deleted_at')->whereNull('s.deleted_at')->sum('p.amount'), Dec::MONEY);
        $drawer = fn (string $refType, string $direction) => Dec::parse((string) DB::table('account_transactions')->where('account_id', $shift->cash_account_id)
            ->where('ref_type', $refType)->where('direction', $direction)->where('created_by', $shift->cashier_id)
            ->whereBetween('occurred_at', [$shift->opened_at, $until])->sum('amount'), Dec::MONEY);
        $cashRefunds = $drawer('sale_refund', 'out');
        // The opening float is booked as a cash movement in; keep it out of "cash in" so it is not counted twice.
        $cashIn = $drawer('shift_cash_movement', 'in') - $float;
        $cashOut = $drawer('shift_cash_movement', 'out');

        return [
            'sales_count' => (int) $sales->n, 'sales_total' => Dec::fmt(Dec::parse((string) $sales->total, Dec::MONEY), Dec::MONEY),
            'void_count' => (int) $voided->n, 'void_total' => Dec::fmt(Dec::parse((string) $voided->total, Dec::MONEY), Dec::MONEY),
            'by_method' => $methods,
            'opening_float' => Dec::fmt($float, Dec::MONEY),
            'cash_sales' => Dec::fmt($cashSales, Dec::MONEY), 'cash_refunds' => Dec::fmt($cashRefunds, Dec::MONEY),
            'cash_in' => Dec::fmt($cashIn, Dec::MONEY), 'cash_out' => Dec::fmt($cashOut, Dec::MONEY),
            'expected_cash' => Dec::fmt($float + $cashSales - $cashRefunds + $cashIn - $cashOut, Dec::MONEY),
        ];
    }

    private function accountEntry(CashShift $shift, string $direction, int $paisa, string $description): void
    {
        AccountTransaction::query()->create([
            'created_by' => $shift->cashier_id, 'branch_id' => $shift->branch_id, 'account_id' => $shift->cash_account_id, 'direction' => $direction, 'amount' => Dec::fmt($paisa, Dec::MONEY),
            'txn_date' => Branches::today(), 'occurred_at' => now(), 'ref_type' => 'shift_cash_movement', 'ref_id' => $shift->id, 'ref_no' => $shift->shift_no, 'description' => $description,
        ]);
    }
}
