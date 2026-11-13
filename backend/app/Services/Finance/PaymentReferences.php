<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\SalePayment;
use App\Support\Branches;
use Illuminate\Support\Facades\DB;

/**
 * BR-060 and BR-061 in one place so a POS payment line and a later receipt or supplier payment are checked identically.
 * Pure checks, no writes.
 */
class PaymentReferences
{
    /**
     * The references a method needs, normalised. Cash needs none.
     *
     * @param  array<string, mixed>  $p  the payment input
     * @param  string  $path  field path used in validation errors, e.g. "payments.0"
     * @return array{sender_number: string|null, trx_id: string|null, bank_name: string|null, bank_ref: string|null, bank_account_no: string|null, transfer_date: string|null}
     */
    public function normalise(string $method, array $p, string $path): array
    {
        $key = fn (string $f): string => $path === '' ? $f : "$path.$f";
        $out = ['sender_number' => null, 'trx_id' => null, 'bank_name' => null, 'bank_ref' => null, 'bank_account_no' => null, 'transfer_date' => null];
        if (in_array($method, SalePayment::MOBILE_MONEY, true)) {
            $sender = preg_replace('/\D+/', '', (string) ($p['sender_number'] ?? ''));
            $trx = strtoupper(trim((string) ($p['trx_id'] ?? '')));
            if (! preg_match('/^01[3-9]\d{8}$/', (string) $sender) || $trx === '') {
                throw new ApiException('PAYMENT_REFERENCE_REQUIRED', 422, '', [$key('trx_id') => ['errors.PAYMENT_REFERENCE_REQUIRED']], ['method' => $method]);
            }
            $out['sender_number'] = $sender;
            $out['trx_id'] = $trx;
            $out['transfer_date'] = Branches::today();
        } elseif ($method === 'bank_transfer') {
            $bankName = trim((string) ($p['bank_name'] ?? ''));
            $ref = trim((string) ($p['bank_ref'] ?? ''));
            if ($bankName === '' || $ref === '') {
                throw new ApiException('PAYMENT_REFERENCE_REQUIRED', 422, '', [$key('bank_ref') => ['errors.PAYMENT_REFERENCE_REQUIRED']], ['method' => $method]);
            }
            $out['bank_name'] = $bankName;
            $out['bank_ref'] = $ref;
            $out['bank_account_no'] = ($p['bank_account_no'] ?? '') !== '' ? trim((string) $p['bank_account_no']) : null;
            $out['transfer_date'] = ($p['transfer_date'] ?? '') !== '' ? (string) $p['transfer_date'] : Branches::today();
        }

        return $out;
    }

    /**
     * 'recent' = used within the window (blocked unless a manager overrides), 'old' = used before the window (allowed),
     * null = new. Looks at POS payments and party payments alike, because one TrxID can only ever pay once.
     *
     * @param  array<string, bool>  $seen  method:trx pairs already used earlier in the same request
     */
    public function duplicateState(string $method, string $trx, int $windowDays, array $seen = []): ?string
    {
        if (isset($seen[$method.':'.$trx])) {
            return 'recent';
        }
        $sale = DB::table('sale_payments')->where(['method' => $method, 'trx_id' => $trx])->where('verification', '<>', 'rejected')->whereNull('deleted_at')->max('received_at');
        $party = DB::table('party_payments')->where(['method' => $method, 'trx_id' => $trx])->where('verification', '<>', 'rejected')->where('status', 'posted')->whereNull('deleted_at')->max('created_at');
        $latest = max((string) $sale, (string) $party);
        if ($latest === '') {
            return null;
        }

        return strtotime($latest.' UTC') >= now()->subDays($windowDays)->getTimestamp() ? 'recent' : 'old';
    }
}
