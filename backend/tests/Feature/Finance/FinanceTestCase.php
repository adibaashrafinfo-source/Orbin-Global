<?php

namespace Tests\Feature\Finance;

use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\PartyPayment;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\AccountLedgerService;
use App\Services\Finance\PartyLedgerService;
use App\Services\Finance\PartyPaymentService;
use App\Services\Inventory\IntegrityService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\SupplierService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

/** Helpers for Phase 4 tests: credit sales, posted receipts, funded accounts and one-line payments. */
abstract class FinanceTestCase extends SalesTestCase
{
    protected int $variant;

    protected User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->variant] = $this->stocked(1, '500');
        $this->accountant = $this->userWithRole('accountant');
    }

    /** A credit invoice for qty × 1,000, with $paid taken in cash at the till. */
    protected function dueSale(Customer $customer, int $qty = 1, string $paid = '0.00'): Sale
    {
        $payments = (float) $paid > 0 ? [['method' => 'cash', 'amount' => $paid]] : [];

        return $this->checkout($this->payload([$this->variant => (string) $qty], ['customer_id' => $customer->id, 'payments' => $payments]));
    }

    /** Put money in an account the way go-live does: through its opening balance. */
    protected function fund(string $type, string $amount): void
    {
        $account = $this->account($type);
        DB::table('financial_accounts')->where('id', $account->id)->update(['opening_balance' => Dec::fmt(Dec::parse((string) $account->opening_balance, 2) + Dec::parse($amount, 2), 2)]);
    }

    /** @param  array<string, mixed>  $over */
    protected function receive(Customer $customer, string $amount, array $over = [], ?User $as = null): PartyPayment
    {
        $user = $as ?? $this->cashier;
        $this->actingAsUser($user);

        return app(PartyPaymentService::class)->record($user, array_merge(['party_type' => 'customer', 'party_id' => $customer->id, 'method' => 'cash', 'amount' => $amount], $over));
    }

    /** @param  array<string, mixed>  $over */
    protected function pay(Supplier $supplier, string $amount, array $over = [], ?User $as = null): PartyPayment
    {
        $user = $as ?? $this->accountant;
        $this->actingAsUser($user);

        return app(PartyPaymentService::class)->record($user, array_merge(['party_type' => 'supplier', 'party_id' => $supplier->id, 'method' => 'cash', 'amount' => $amount], $over));
    }

    /** A supplier with one posted goods receipt of qty × cost. */
    protected function supplierWithReceipt(string $qty = '10', string $cost = '100', ?string $date = null): array
    {
        $this->actingAsRole('super_admin');
        $supplier = app(SupplierService::class)->create(['name' => 'Taiwan Mirror Co '.random_int(100, 999), 'supplier_type' => 'import']);
        $grn = app(GoodsReceiptService::class)->post(app(GoodsReceiptService::class)->create([
            'warehouse_id' => $this->showroom, 'supplier_id' => $supplier->id, 'received_date' => $date ?? now('Asia/Dhaka')->toDateString(),
            'allocation_basis' => 'value', 'items' => [['variant_id' => $this->variant, 'qty_received' => $qty, 'qty_damaged' => '0', 'unit_cost' => $cost]], 'extra_costs' => [],
        ]));

        return [$supplier, GoodsReceipt::query()->findOrFail($grn->id)];
    }

    protected function customerBalance(Customer $c): string
    {
        return Dec::fmt(app(PartyLedgerService::class)->balance('customer', $c->id), 2);
    }

    protected function supplierBalance(Supplier $s): string
    {
        return Dec::fmt(app(PartyLedgerService::class)->balance('supplier', $s->id), 2);
    }

    /** What the account holds, not counting the 1,000 float the test shift opened the cash drawer with. */
    protected function accountBalance(string $type): string
    {
        $float = $type === 'cash' ? Dec::parse('1000', 2) : 0;

        return Dec::fmt(app(AccountLedgerService::class)->balance($this->account($type)->id) - $float, 2);
    }

    /** @return array<int, string> keys of integrity checks that failed */
    protected function failedChecks(): array
    {
        return collect(app(IntegrityService::class)->run()['checks'])->where('status', 'fail')->pluck('key')->all();
    }

    protected function assertBooksHold(): void
    {
        $this->assertSame([], $this->failedChecks(), 'integrity check failed');
    }

    protected function sale(Sale $sale): Sale
    {
        return Sale::query()->findOrFail($sale->id);
    }
}
