<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\SalePayment;
use App\Services\Finance\AccountLedgerService;
use App\Services\Finance\PaymentReferences;
use App\Services\SettingsService;
use App\Support\Dec;

/** FR-801 to FR-806, BR-060, BR-061: turns the payment lines of a cart into rows that are safe to store. Pure checks, no writes. */
class SalePayments
{
    public const ACCOUNT_TYPE = AccountLedgerService::TYPE_OF_METHOD;

    public function __construct(private readonly SettingsService $settings, private readonly PaymentReferences $references, private readonly AccountLedgerService $accounts) {}

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{lines: array<int, array<string, mixed>>, paid: int, change: int, duplicates: array<int, int>} duplicates = line indexes whose TrxID needs a manager
     */
    public function prepare(array $payments, int $grandTotal): array
    {
        $lines = [];
        $paid = 0;
        $change = 0;
        $duplicates = [];
        $seen = [];
        $window = (int) $this->settings->get('duplicate_trx_days');
        $pending = (bool) $this->settings->get('require_verification_for_mfs');

        foreach (array_values($payments) as $i => $p) {
            $method = (string) ($p['method'] ?? '');
            if (! array_key_exists($method, self::ACCOUNT_TYPE)) {
                throw new ApiException('VALIDATION', 422, '', ["payments.$i.method" => ['errors.REQUIRED']]);
            }
            $amount = Dec::parse((string) ($p['amount'] ?? '0'), Dec::MONEY);
            if ($amount <= 0) {
                throw new ApiException('VALIDATION', 422, '', ["payments.$i.amount" => ['errors.REQUIRED']]);
            }
            $account = $this->accounts->resolve($method, isset($p['account_id']) ? (int) $p['account_id'] : null, "payments.$i.account_id");
            $line = [
                'method' => $method, 'amount' => $amount, 'account_id' => $account->id, 'tendered' => null, 'change' => 0,
                'sender_number' => null, 'trx_id' => null, 'bank_name' => null, 'bank_account_no' => null, 'bank_ref' => null, 'transfer_date' => null,
                'attachment_id' => isset($p['attachment_id']) ? (int) $p['attachment_id'] : null, 'verification' => 'confirmed',
                'trx_key' => null, 'duplicate_override' => false,
            ];

            if ($method === 'cash') {
                $tendered = isset($p['tendered']) && $p['tendered'] !== '' ? Dec::parse((string) $p['tendered'], Dec::MONEY) : $amount;
                if ($tendered < $amount) {
                    throw new ApiException('VALIDATION', 422, '', ["payments.$i.tendered" => ['errors.TENDERED_TOO_LOW']]);
                }
                $line['tendered'] = $tendered;
                $line['change'] = $tendered - $amount;
                $change += $line['change'];
            } else {
                $refs = $this->references->normalise($method, $p, "payments.$i");
                $line = array_merge($line, $refs, ['verification' => $pending ? 'pending' : 'confirmed']);
                if (in_array($method, SalePayment::MOBILE_MONEY, true)) {
                    $line['trx_key'] = $method.':'.$refs['trx_id'];
                    $state = $this->references->duplicateState($method, (string) $refs['trx_id'], $window, $seen);
                    if ($state === 'recent') {
                        $duplicates[] = $i;
                        $line['duplicate_override'] = true;
                        $line['trx_key'] = null;
                    } elseif ($state === 'old') {
                        $line['trx_key'] = null;
                    }
                    $seen[$method.':'.$refs['trx_id']] = true;
                }
            }
            $paid += $amount;
            $lines[] = $line;
        }

        if ($paid > $grandTotal) {
            // Cash change is already separate (tendered − amount), so what is applied can never exceed the bill.
            throw new ApiException('OVERPAYMENT', 422, '', [], ['grand_total' => Dec::fmt($grandTotal, Dec::MONEY), 'paid' => Dec::fmt($paid, Dec::MONEY)]);
        }

        return ['lines' => $lines, 'paid' => $paid, 'change' => $change, 'duplicates' => $duplicates];
    }
}
