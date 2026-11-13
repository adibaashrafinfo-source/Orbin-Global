<?php

namespace App\Reports;

use App\Support\Branches;
use Carbon\CarbonImmutable;

/**
 * What a report is asked for. Dates are Dhaka calendar days (BR-090); datetime columns are stored in UTC, so the range is
 * converted once here and every report uses the same boundaries.
 */
final class ReportFilters
{
    /** @param  array<string, mixed>  $extra  report specific options: group, variant_id, search, … */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $branchId = null,
        public readonly ?int $warehouseId = null,
        public readonly string $asOf = '',
        public readonly array $extra = [],
    ) {}

    /** @param  array<string, mixed>  $in */
    public static function fromInput(array $in): self
    {
        $today = Branches::today();
        $to = self::date($in['to'] ?? null) ?? $today;
        $from = self::date($in['from'] ?? null) ?? substr($to, 0, 8).'01';
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $known = ['group', 'variant_id', 'search', 'status', 'supplier_id'];

        return new self(
            $from, $to,
            ($in['branch_id'] ?? '') !== '' ? (int) $in['branch_id'] : null,
            ($in['warehouse_id'] ?? '') !== '' ? (int) $in['warehouse_id'] : null,
            self::date($in['as_of'] ?? null) ?? $to,
            array_intersect_key($in, array_flip($known)),
        );
    }

    private static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }

    /** First instant of the range, UTC, for a datetime column (inclusive). */
    public function fromUtc(): string
    {
        return CarbonImmutable::parse($this->from, 'Asia/Dhaka')->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    /** First instant after the range, UTC (exclusive), so the last day is whole. */
    public function toUtcExclusive(): string
    {
        return CarbonImmutable::parse($this->to, 'Asia/Dhaka')->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    public function asOfEndUtc(): string
    {
        return CarbonImmutable::parse($this->asOf, 'Asia/Dhaka')->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /** The period of the same length that ends the day before this one starts ("compare to previous period"). */
    public function previous(): self
    {
        $len = $this->days();
        $to = CarbonImmutable::parse($this->from)->subDay();
        $from = $to->subDays($len - 1);

        return new self($from->toDateString(), $to->toDateString(), $this->branchId, $this->warehouseId, $to->toDateString(), $this->extra);
    }

    public function extra(string $key, mixed $default = null): mixed
    {
        $v = $this->extra[$key] ?? null;

        return $v === null || $v === '' ? $default : $v;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'branch_id' => $this->branchId, 'warehouse_id' => $this->warehouseId, 'as_of' => $this->asOf] + $this->extra;
    }
}
