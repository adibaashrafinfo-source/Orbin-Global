<?php

namespace Tests\Feature\Sales;

use App\Exceptions\ApiException;
use App\Models\AccountTransaction;
use App\Models\CashShift;
use App\Services\Sales\InvoicePdfService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Services\Sales\ShiftService;

/** FR-716: the drawer arithmetic behind the Z-report, worked by hand. */
class ShiftServiceTest extends SalesTestCase
{
    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (ApiException $e) {
            return $e->errorCode;
        }

        return 'NO_ERROR';
    }

    public function test_the_float_is_booked_into_the_drawer_and_a_second_shift_is_refused(): void
    {
        $this->assertSame('1000.00', $this->shift->opening_float);
        $this->assertStringStartsWith('ORB-SH-', $this->shift->shift_no);
        $tx = AccountTransaction::query()->where(['ref_type' => 'shift_cash_movement', 'ref_id' => $this->shift->id])->first();
        $this->assertSame(['in', '1000.00'], [$tx->direction, $tx->amount]);
        $this->assertSame('SHIFT_ALREADY_OPEN', $this->code(fn () => app(ShiftService::class)->open($this->cashier, $this->account('cash')->id, '0')));
    }

    public function test_expected_cash_is_float_plus_cash_sales_less_refunds_plus_in_less_out(): void
    {
        [$v] = $this->stocked(1, '20');
        // 2000 cash + 500 bKash: only the cash counts in the drawer.
        $this->checkout($this->payload([$v => '2.5'], ['payments' => [['method' => 'cash', 'amount' => '2000.00'], ['method' => 'bkash', 'amount' => '500.00', 'sender_number' => '01712345678', 'trx_id' => 'SH1']]]));
        $voided = $this->checkout($this->payload([$v => '1']));
        app(SaleService::class)->void($this->userWithRole('manager'), $voided, 'wrong item');
        $refunded = $this->checkout($this->payload([$v => '1']));
        // The cashier makes a 300 cash refund on the second sale (approved by a manager).
        $this->actingAsUser($this->cashier);
        $ret = app(SaleReturnService::class)->create($this->cashier, $refunded->refresh(), ['items' => [['sale_item_id' => $refunded->items()->first()->id, 'qty' => '0.3', 'condition' => 'resellable']], 'refund_mode' => 'cash']);
        app(SaleReturnService::class)->post($this->userWithRole('manager'), $ret);

        $svc = app(ShiftService::class);
        $svc->cashMovement($this->shift->refresh(), 'in', '100.00', 'change from the bank');
        $svc->cashMovement($this->shift->refresh(), 'out', '200.00', 'bank deposit');
        // 1000 float + (2000 + 1000 cash sales; the voided 1000 drops out) − 300 refund + 100 − 200 = 3600.
        $t = $svc->totals($this->shift->refresh());
        $this->assertSame(['1000.00', '3000.00', '300.00', '100.00', '200.00', '3600.00'], [$t['opening_float'], $t['cash_sales'], $t['cash_refunds'], $t['cash_in'], $t['cash_out'], $t['expected_cash']]);
        $this->assertSame(['count' => 2, 'total' => '3000.00'], $t['by_method']['cash']);
        $this->assertSame(['count' => 1, 'total' => '500.00'], $t['by_method']['bkash']);
        $this->assertSame([2, 1], [$t['sales_count'], $t['void_count']]);
    }

    public function test_closing_counts_the_notes_and_shows_the_variance(): void
    {
        [$v] = $this->stocked(1, '5');
        $this->checkout($this->payload([$v => '1']));
        $svc = app(ShiftService::class);
        // Expected 2000. Counted: 1 × 1000 + 1 × 500 + 4 × 100 + 2 × 50 = 2000 − 0; then try 1900.
        $closed = $svc->close($this->shift, ['1000' => 1, '500' => 1, '100' => 3, '50' => 2], 'short by one note');
        $this->assertSame(['closed', '2000.00', '1900.00', '-100.00'], [$closed->status, $closed->expected_cash, $closed->counted_cash, $closed->variance]);
        $this->assertNull($closed->open_key, 'the shift no longer blocks a new one');
        $this->assertNotNull($closed->totals);
        $this->assertSame('ALREADY_CLOSED', $this->code(fn () => $svc->close($closed, [], null)));
        $this->assertSame('%PDF', substr(app(InvoicePdfService::class)->zReport($closed), 0, 4));
        $again = $svc->open($this->cashier, $this->account('cash')->id, '500');
        $this->assertNotSame($this->shift->id, $again->id);
    }

    public function test_cash_out_cannot_exceed_what_is_in_the_drawer_and_needs_a_reason(): void
    {
        $svc = app(ShiftService::class);
        $this->assertSame('INSUFFICIENT_CASH', $this->code(fn () => $svc->cashMovement($this->shift, 'out', '1500.00', 'too much')));
        $this->assertSame('VALIDATION', $this->code(fn () => $svc->cashMovement($this->shift, 'out', '10.00', '  ')));
        $svc->close($this->shift, [], null);
        $this->assertSame('ALREADY_CLOSED', $this->code(fn () => $svc->cashMovement($this->shift->refresh(), 'in', '10.00', 'late')));
    }

    public function test_only_one_shift_per_cashier_can_be_open_even_in_a_race(): void
    {
        $svc = app(ShiftService::class);
        $this->assertSame(1, CashShift::query()->where('status', 'open')->count());
        $this->assertSame('SHIFT_ALREADY_OPEN', $this->code(fn () => $svc->assertNoOpen($this->cashier)));
    }
}
