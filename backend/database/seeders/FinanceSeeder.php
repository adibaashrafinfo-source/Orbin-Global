<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\FinancialAccount;
use Illuminate\Database\Seeder;

/** Walk-in customer and the five payment accounts (spec seedData). Idempotent; never overwrites admin edits. */
class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'HQ')->firstOrFail();
        $accounts = [
            ['CASH-01', 'Cash Drawer', 'cash', null],
            ['BKASH-01', 'bKash', 'bkash', 'bKash'],
            ['NAGAD-01', 'Nagad', 'nagad', 'Nagad'],
            ['ROCKET-01', 'Rocket', 'rocket', 'Rocket'],
            ['BANK-01', 'Bank Account', 'bank', ''],
        ];
        FinancialAccount::withoutEvents(function () use ($branch, $accounts): void {
            foreach ($accounts as [$code, $name, $type, $provider]) {
                FinancialAccount::query()->firstOrCreate(
                    ['code' => $code],
                    ['branch_id' => $branch->id, 'name' => $name, 'account_type' => $type, 'provider' => $provider, 'opening_balance' => 0, 'opening_date' => now('Asia/Dhaka')->toDateString(), 'is_default_for_method' => true, 'is_active' => true],
                );
            }
        });
        Customer::withoutEvents(function (): void {
            $walkIn = Customer::query()->where('is_walk_in', true)->first();
            if ($walkIn === null) {
                $walkIn = new Customer(['code' => 'CUS-WALKIN', 'name' => 'Walk-in Customer', 'phones' => ['00000000000'], 'price_tier' => 'retail']);
                $walkIn->is_walk_in = true;
                $walkIn->walkin_key = 1;
                $walkIn->save();
            }
        });
    }
}
