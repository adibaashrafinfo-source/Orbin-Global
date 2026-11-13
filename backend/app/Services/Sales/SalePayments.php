<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\FinancialAccount;
use App\Models\SalePayment;
use App\Services\SettingsService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** FR-801 to FR-806, BR-060, BR-061: turns the payment lines of a cart into rows that are safe to store. Pure checks, no writes. */
class SalePayments
{
    public const ACCOUNT_TYPE = ['cash' => 'cash', 'bkash' => 'bkash', 'nagad' => 'nagad', 'rocket' => 'rocket', 'bank_transfer' => 'bank'];

    public function __construct(private readonly SettingsService $settings) {}

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
            $account = $this->account($method, isset($p['account_id']) ? (int) $p['account_id'] : null, $i);
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
            } elseif (in_array($method, SalePayment::MOBILE_MONEY, true)) {
                $sender = preg_replace('/\D+/', '', (string) ($p['sender_number'] ?? ''));
                $trx = strtoupper(trim((string) ($p['trx_id'] ?? '')));
                if (! preg_match('/^01[3-9]\d{8}$/', (string) $sender) || $trx === '') {
                    throw new ApiException('PAYMENT_REFERENCE_REQUIRED', 422, '', ["payments.$i.trx_id" => ['errors.PAYMENT_REFERENCE_REQUIRED']], ['method' => $method]);
                }
                $line['sender_number'] = $sender;
                $line['trx_id'] = $trx;
                $line['verification'] = $pending ? 'pending' : 'confirmed';
                $line['trx_key'] = $method.':'.$trx;
                $line['transfer_date'] = Branches::today();
                $state = $this->duplicateState($method, $trx, $window, $seen);
                if ($state === 'recent') {
                    $duplicates[] = $i;
                    $line['duplicate_override'] = true;
                    $line['trx_key'] = null;
                } elseif ($state === 'old') {
                    $line['trx_key'] = null;
                }
                $seen[$method.':'.$trx] = true;
            } else {
                $bankName = trim((string) ($p['bank_name'] ?? ''));
                $ref = trim((string) ($p['bank_ref'] ?? ''));
                if ($bankName === '' || $ref === '') {
                    throw new ApiException('PAYMENT_REFERENCE_REQUIRED', 422, '', ["payments.$i.bank_ref" => ['errors.PAYMENT_REFERENCE_REQUIRED']], ['method' => $method]);
                }
                $line['bank_name'] = $bankName;
                $line['bank_ref'] = $ref;
                $line['bank_account_no'] = ($p['bank_account_no'] ?? '') !== '' ? trim((string) $p['bank_account_no']) : null;
                $line['transfer_date'] = ($p['transfer_date'] ?? '') !== '' ? (string) $p['transfer_date'] : Branches::today();
                $line['verification'] = $pending ? 'pending' : 'confirmed';
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

    private function account(string $method, ?int $accountId, int $i): FinancialAccount
    {
        $type = self::ACCOUNT_TYPE[$method];
        $q = FinancialAccount::query()->where(['account_type' => $type, 'is_active' => true]);
        $account = $accountId
            ? (clone $q)->whereKey($accountId)->first()
            : ((clone $q)->where('is_default_for_method', true)->orderBy('id')->first() ?? (clone $q)->orderBy('id')->first());
        if ($account === null) {
            throw new ApiException('VALIDATION', 422, '', ["payments.$i.account_id" => ['errors.ACCOUNT_REQUIRED']]);
        }

        return $account;
    }

    /**
     * 'recent' = used within the window (blocked unless a manager overrides), 'old' = used before the window (allowed),
     * null = new. The same TrxID twice inside one cart counts as recent.
     *
     * @param  array<string, bool>  $seen
     */
    private function duplicateState(string $method, string $trx, int $windowDays, array $seen): ?string
    {
        if (isset($seen[$method.':'.$trx])) {
            return 'recent';
        }
        $hit = DB::table('sale_payments')->where(['method' => $method, 'trx_id' => $trx])->where('verification', '<>', 'rejected')->whereNull('deleted_at')->orderByDesc('received_at')->first(['received_at']);
        if ($hit === null) {
            return null;
        }

        return strtotime((string) $hit->received_at.' UTC') >= now()->subDays($windowDays)->getTimestamp() ? 'recent' : 'old';
    }
}
