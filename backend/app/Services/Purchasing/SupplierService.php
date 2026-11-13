<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\PartyLedgerEntry;
use App\Models\Supplier;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** FR-501. What we owe a supplier is the append-only party ledger: credits raise it, debits lower it. */
class SupplierService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Supplier
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($data): Supplier {
                    $data['code'] = trim((string) ($data['code'] ?? '')) !== '' ? strtoupper(trim($data['code'])) : $this->nextCode();
                    $supplier = Supplier::query()->create($data);
                    $opening = Dec::parse((string) ($data['opening_balance'] ?? '0'), Dec::MONEY);
                    if ($opening > 0) {
                        $this->ledger($supplier, 'opening', 0, $opening, null, null, __('Opening balance'));
                    }

                    return $supplier;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3 || ! empty($data['code'])) {
                    throw new ApiException('CODE_DUPLICATE', 409, '', ['code' => ['errors.CODE_DUPLICATE']]);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(Supplier $supplier, array $data): Supplier
    {
        // The opening balance is a ledger entry; changing it later needs an adjustment entry (Phase 4), not an edit.
        unset($data['opening_balance']);
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }
        $supplier->update($data);

        return $supplier->refresh();
    }

    public function delete(Supplier $supplier): void
    {
        $used = DB::table('purchase_orders')->where('supplier_id', $supplier->id)->whereNull('deleted_at')->exists()
            || DB::table('goods_receipts')->where('supplier_id', $supplier->id)->whereNull('deleted_at')->exists()
            || DB::table('purchase_returns')->where('supplier_id', $supplier->id)->whereNull('deleted_at')->exists()
            || DB::table('party_ledger_entries')->where(['party_type' => 'supplier', 'party_id' => $supplier->id])->where('entry_type', '<>', 'opening')->exists();
        if ($used) {
            throw new ApiException('IN_USE', 409);
        }
        $supplier->delete();
    }

    /** Amount we owe: Σ credit − Σ debit, as a money string. */
    public function balance(int $supplierId): string
    {
        $row = DB::table('party_ledger_entries')->where(['party_type' => 'supplier', 'party_id' => $supplierId])
            ->selectRaw('COALESCE(SUM(credit), 0) as c, COALESCE(SUM(debit), 0) as d')->first();

        return Dec::fmt(Dec::parse((string) $row->c, Dec::MONEY) - Dec::parse((string) $row->d, Dec::MONEY), Dec::MONEY);
    }

    /** Post one ledger line (credit = we owe more). Amounts are paisa. */
    public function ledger(Supplier $supplier, string $type, int $debit, int $credit, ?string $refTable, ?int $refId, string $description, ?string $refNo = null, ?string $date = null, ?int $branchId = null): PartyLedgerEntry
    {
        return PartyLedgerEntry::query()->create([
            'branch_id' => $branchId ?? Branches::currentId(), 'party_type' => 'supplier', 'party_id' => $supplier->id,
            'entry_date' => $date ?? Branches::today(), 'entry_type' => $type,
            'debit' => Dec::fmt($debit, Dec::MONEY), 'credit' => Dec::fmt($credit, Dec::MONEY),
            'ref_table' => $refTable, 'ref_id' => $refId, 'ref_no' => $refNo, 'description' => $description,
        ]);
    }

    private function nextCode(): string
    {
        $max = (int) DB::table('suppliers')->where('code', 'like', 'SUP-%')->selectRaw('MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as m')->value('m');

        return 'SUP-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
