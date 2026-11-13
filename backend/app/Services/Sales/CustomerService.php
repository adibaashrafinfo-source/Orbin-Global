<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\PartyLedgerEntry;
use App\Services\NumberService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-601 to FR-606. What a customer owes is the append-only party ledger: debits raise it, credits lower it. */
class CustomerService
{
    public function __construct(private readonly NumberService $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $acknowledgeDuplicate  the user saw the "same phone" warning and chose to create anyway (FR-605)
     */
    public function create(array $data, bool $acknowledgeDuplicate = false): Customer
    {
        $primary = $this->digits($data['phones'][0] ?? '');
        if (! $acknowledgeDuplicate && $primary !== '') {
            $existing = Customer::query()->where('phone_primary', $primary)->first();
            if ($existing !== null) {
                throw new ApiException('CUSTOMER_PHONE_EXISTS', 409, '', ['phones' => ['errors.CUSTOMER_PHONE_EXISTS']], ['existing' => ['id' => $existing->id, 'code' => $existing->code, 'name' => $existing->name]]);
            }
        }

        return DB::transaction(function () use ($data): Customer {
            $data['code'] = $this->numbers->next('customer', Branches::currentId());
            $this->applyTier($data);
            $customer = Customer::query()->create($data);
            $opening = Dec::parse((string) ($data['opening_balance'] ?? '0'), Dec::MONEY);
            if ($opening > 0) {
                $this->ledger($customer->id, 'opening', $opening, 0, null, null, 'Opening balance');
            }

            // Re-read so the response carries the database defaults (credit limit 0, tier and so on), not just what was sent.
            return $customer->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Customer $customer, array $data): Customer
    {
        if ($customer->is_walk_in) {
            throw new ApiException('WALKIN_PROTECTED', 422);
        }
        // The opening balance is a ledger entry; changing it later is an adjustment entry (Phase 4), not an edit.
        unset($data['opening_balance'], $data['code']);
        $this->applyTier($data);
        $customer->update($data);

        return $customer->refresh();
    }

    public function delete(Customer $customer): void
    {
        if ($customer->is_walk_in) {
            throw new ApiException('WALKIN_PROTECTED', 422);
        }
        $used = DB::table('sales')->where('customer_id', $customer->id)->whereNull('deleted_at')->exists()
            || DB::table('quotations')->where('customer_id', $customer->id)->whereNull('deleted_at')->exists()
            || DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $customer->id])->where('entry_type', '<>', 'opening')->exists();
        if ($used) {
            throw new ApiException('IN_USE', 409);
        }
        $customer->delete();
    }

    /** What the customer owes now: Σ debit − Σ credit, as a money string (negative = advance). */
    public function balance(int $customerId): string
    {
        $row = DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $customerId])
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        return Dec::fmt(Dec::parse((string) $row->d, Dec::MONEY) - Dec::parse((string) $row->c, Dec::MONEY), Dec::MONEY);
    }

    /** Post one ledger line. Amounts are paisa. */
    public function ledger(int $customerId, string $type, int $debit, int $credit, ?string $refTable, ?int $refId, string $description, ?string $refNo = null, ?string $date = null, ?int $branchId = null): PartyLedgerEntry
    {
        return PartyLedgerEntry::query()->create([
            'branch_id' => $branchId ?? Branches::currentId(), 'party_type' => 'customer', 'party_id' => $customerId,
            'entry_date' => $date ?? Branches::today(), 'entry_type' => $type,
            'debit' => Dec::fmt($debit, Dec::MONEY), 'credit' => Dec::fmt($credit, Dec::MONEY),
            'ref_table' => $refTable, 'ref_id' => $refId, 'ref_no' => $refNo, 'description' => $description,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function saveVehicle(Customer $customer, array $data, ?CustomerVehicle $vehicle = null): CustomerVehicle
    {
        $data['reg_no'] = strtoupper(trim((string) $data['reg_no']));
        $clash = CustomerVehicle::query()->where(['customer_id' => $customer->id, 'reg_no' => $data['reg_no']])->when($vehicle, fn ($q) => $q->where('id', '<>', $vehicle->id))->exists();
        if ($clash) {
            throw new ApiException('VEHICLE_DUPLICATE', 409, '', ['reg_no' => ['errors.VEHICLE_DUPLICATE']]);
        }
        if ($vehicle === null) {
            return CustomerVehicle::query()->create($data + ['customer_id' => $customer->id]);
        }
        $vehicle->update($data);

        return $vehicle->refresh();
    }

    /** @param  array<string, mixed>  $data */
    private function applyTier(array &$data): void
    {
        // The price tier follows the customer type unless the owner set one by hand (BR-002).
        if (empty($data['price_tier'])) {
            $data['price_tier'] = 'retail';
        }
    }

    private function digits(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }
}
