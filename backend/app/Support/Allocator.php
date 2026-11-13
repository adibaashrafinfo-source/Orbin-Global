<?php

namespace App\Support;

use App\Exceptions\ApiException;

/**
 * BR-062: settle documents oldest first. Documents arrive already sorted oldest first; amounts are paisa.
 * A document is {table, id, open}. The key of an allocation is "table:id".
 */
class Allocator
{
    /**
     * @param  array<int, array{table: string, id: int, open: int}>  $docs
     * @return array{allocations: array<int, array{table: string, id: int, amount: int}>, remainder: int}
     */
    public static function oldestFirst(array $docs, int $amount): array
    {
        $left = $amount;
        $out = [];
        foreach ($docs as $doc) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $doc['open']);
            if ($take > 0) {
                $out[] = ['table' => $doc['table'], 'id' => $doc['id'], 'amount' => $take];
                $left -= $take;
            }
        }

        return ['allocations' => $out, 'remainder' => $left];
    }

    /**
     * The user's own split. Each line may not exceed what its document still owes, and the total may not exceed the amount.
     *
     * @param  array<int, array{table: string, id: int, open: int}>  $docs
     * @param  array<int, array{table: string, id: int, amount: int}>  $requested
     * @return array{allocations: array<int, array{table: string, id: int, amount: int}>, remainder: int}
     */
    public static function manual(array $docs, array $requested, int $amount): array
    {
        $open = [];
        foreach ($docs as $doc) {
            $open[$doc['table'].':'.$doc['id']] = $doc['open'];
        }
        $merged = [];
        foreach ($requested as $line) {
            if ($line['amount'] <= 0) {
                continue;
            }
            $key = $line['table'].':'.$line['id'];
            $merged[$key] = ($merged[$key] ?? 0) + $line['amount'];
        }
        $total = 0;
        $out = [];
        foreach ($merged as $key => $amt) {
            if (! isset($open[$key]) || $amt > $open[$key]) {
                throw new ApiException('ALLOCATION_EXCEEDS_DUE', 422, '', [], ['document' => $key]);
            }
            [$table, $id] = explode(':', $key);
            $out[] = ['table' => $table, 'id' => (int) $id, 'amount' => $amt];
            $total += $amt;
        }
        if ($total > $amount) {
            throw new ApiException('ALLOCATION_EXCEEDS_AMOUNT', 422);
        }

        return ['allocations' => $out, 'remainder' => $amount - $total];
    }
}
