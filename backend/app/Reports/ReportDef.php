<?php

namespace App\Reports;

use Closure;

/**
 * One entry of the report catalog (spec `reports`). `rows` yields arrays keyed by column key; an optional `_links` entry
 * maps a column key to {table, id} so a document number can link to its document (drill-down). Money is a decimal string.
 */
final class ReportDef
{
    /**
     * @param  array<int, string>  $permissions  any one of these lets a person open the report
     * @param  array<int, Col>  $columns
     * @param  Closure(ReportFilters, ReportContext): iterable<array<string, mixed>>  $rows
     * @param  Closure(array<string, string>): array<string, string>|null  $derive  adds totals that are not plain sums (an average ticket)
     * @param  array<string, mixed>  $options  range|asof|none, branch, warehouse, compare, group (list of choices), variant, search
     * @param  array{type: string, x: string, y: string}|null  $chart
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $domain,
        public readonly array $permissions,
        public readonly array $columns,
        public readonly Closure $rows,
        public readonly ?Closure $derive = null,
        public readonly array $options = [],
        public readonly ?array $chart = null,
        public readonly bool $ownable = false,
        public readonly bool $needsCost = false,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'domain' => $this->domain, 'options' => $this->options + ['range' => 'range'], 'chart' => $this->chart, 'needs_cost' => $this->needsCost];
    }
}
