<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\NumberSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gap-free document numbers (BR-070, FR-106).
 * Must be called inside the caller's DB transaction: the sequence row is locked with SELECT ... FOR UPDATE,
 * so concurrent callers serialize and every number is used exactly once.
 */
class NumberService
{
    public function next(string $key, ?int $branchId, ?CarbonInterface $docDate = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('NumberService::next must run inside a database transaction.');
        }
        $docDate ??= now('Asia/Dhaka');
        $dhaka = $docDate->copy()->setTimezone('Asia/Dhaka');

        /** @var NumberSequence|null $template */
        $template = NumberSequence::query()
            ->where('key', $key)
            ->where(fn ($q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId))
            ->where('period_key', 'all')
            ->lockForUpdate()
            ->first();

        if ($template === null && $branchId !== null) {
            // Fall back to the global (branch-less) sequence definition.
            $template = NumberSequence::query()->where('key', $key)->whereNull('branch_id')->where('period_key', 'all')->lockForUpdate()->first();
        }
        if ($template === null) {
            throw new ApiException('SEQUENCE_MISSING', 500);
        }

        $periodKey = match ($template->reset_policy) {
            'yearly' => $dhaka->format('Y'),
            'monthly' => $dhaka->format('Y-m'),
            default => 'all',
        };

        if ($periodKey === 'all') {
            $row = $template;
        } else {
            // Per-period counter row (same key/branch, period_key = Y or Y-m); the template row keeps the format.
            $row = NumberSequence::query()
                ->where('key', $key)
                ->where(fn ($q) => $template->branch_id === null ? $q->whereNull('branch_id') : $q->where('branch_id', $template->branch_id))
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                $row = NumberSequence::query()->create([
                    'key' => $key,
                    'branch_id' => $template->branch_id,
                    'prefix' => $template->prefix,
                    'date_token' => $template->date_token,
                    'padding' => $template->padding,
                    'reset_policy' => $template->reset_policy,
                    'period_key' => $periodKey,
                    'next_value' => 1,
                ]);
                // Re-lock the freshly inserted row so a concurrent creator (blocked on the unique key) serializes behind us.
                $row = NumberSequence::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            }
        }

        $value = (int) $row->next_value;
        $number = $this->format($template->prefix, $template->date_token, $template->padding, $value, $dhaka);

        // Bump the counter without firing the Auditable observer (counter ticks are not business changes).
        NumberSequence::query()->whereKey($row->id)->update(['next_value' => $value + 1]);

        return $number;
    }

    /** Build "ORB-INV-2511-000001" from prefix, date token (YYMM / YYMMDD / YYYY / empty), padding, counter. */
    public function format(string $prefix, ?string $dateToken, int $padding, int $value, CarbonInterface $date): string
    {
        $parts = [$prefix];
        $token = trim((string) $dateToken);
        if ($token !== '') {
            $parts[] = $this->renderToken($token, $date);
        }
        $parts[] = str_pad((string) $value, max(1, $padding), '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    private function renderToken(string $token, CarbonInterface $date): string
    {
        return strtr($token, [
            'YYYY' => $date->format('Y'),
            'YY' => $date->format('y'),
            'MM' => $date->format('m'),
            'DD' => $date->format('d'),
        ]);
    }

    /** Preview of the next number for the settings screen (no lock, no increment). */
    public function preview(NumberSequence $sequence, ?CarbonInterface $date = null): string
    {
        $date ??= now('Asia/Dhaka');

        return $this->format($sequence->prefix, $sequence->date_token, $sequence->padding, (int) $sequence->next_value, $date);
    }
}
