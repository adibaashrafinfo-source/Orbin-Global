<?php

namespace App\Reports;

use App\Support\Dec;
use Generator;

/** Runs a report for the screen (one page, totals, optional comparison) or for an export (streamed, nothing kept). */
class ReportRunner
{
    /** @return array<int, Col> */
    public function columns(ReportDef $def, ReportContext $ctx): array
    {
        return array_values(array_filter($def->columns, fn (Col $c) => ! $c->cost || $ctx->canCost));
    }

    /** Rows limited to the columns this person may see. @return Generator<int, array<string, mixed>> */
    public function stream(ReportDef $def, ReportFilters $f, ReportContext $ctx): Generator
    {
        $keys = array_flip(array_map(fn (Col $c) => $c->key, $this->columns($def, $ctx)));
        foreach (($def->rows)($f, $ctx) as $row) {
            $out = array_intersect_key($row, $keys);
            if (isset($row['_links'])) {
                $out['_links'] = $row['_links'];
            }
            yield $out;
        }
    }

    /**
     * @return array{columns: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, totals: array<string, string>, meta: array<string, mixed>, compare: array<string, mixed>|null}
     */
    public function run(ReportDef $def, ReportFilters $f, ReportContext $ctx, int $page, int $perPage, ?string $sort, string $dir, bool $compare): array
    {
        $cols = $this->columns($def, $ctx);
        $rows = [];
        $acc = $this->accumulator($cols);
        foreach ($this->stream($def, $f, $ctx) as $row) {
            $this->add($acc, $cols, $row);
            $rows[] = $row;
        }
        $totals = $this->finish($def, $acc, $cols);
        if ($sort !== null && in_array($sort, array_map(fn (Col $c) => $c->key, $cols), true)) {
            $numeric = in_array(collect($cols)->firstWhere('key', $sort)?->type, ['int', 'qty', 'money', 'cost', 'percent'], true);
            usort($rows, function ($a, $b) use ($sort, $numeric, $dir) {
                $r = $numeric ? ((float) ($a[$sort] ?? 0)) <=> ((float) ($b[$sort] ?? 0)) : strcasecmp((string) ($a[$sort] ?? ''), (string) ($b[$sort] ?? ''));

                return $dir === 'desc' ? -$r : $r;
            });
        }
        $total = count($rows);
        $out = [
            'columns' => array_map(fn (Col $c) => $c->toArray(), $cols),
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'totals' => $totals,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'filters' => $f->toArray()],
            'compare' => null,
        ];
        if ($compare && ($def->options['compare'] ?? false)) {
            $prev = $f->previous();
            $pt = $this->totals($def, $prev, $ctx);
            $out['compare'] = ['from' => $prev->from, 'to' => $prev->to, 'totals' => $pt, 'delta' => $this->delta($cols, $totals, $pt)];
        }

        return $out;
    }

    /** @return array<string, string> */
    public function totals(ReportDef $def, ReportFilters $f, ReportContext $ctx): array
    {
        $cols = $this->columns($def, $ctx);
        $acc = $this->accumulator($cols);
        foreach ($this->stream($def, $f, $ctx) as $row) {
            $this->add($acc, $cols, $row);
        }

        return $this->finish($def, $acc, $cols);
    }

    /** @param  array<int, Col>  $cols @return array<string, int> */
    private function accumulator(array $cols): array
    {
        $acc = [];
        foreach ($cols as $c) {
            if ($c->sum) {
                $acc[$c->key] = 0;
            }
        }

        return $acc;
    }

    /** @param  array<string, int>  $acc @param  array<int, Col>  $cols @param  array<string, mixed>  $row */
    private function add(array &$acc, array $cols, array $row): void
    {
        foreach ($cols as $c) {
            if (! $c->sum) {
                continue;
            }
            $acc[$c->key] += match ($c->type) {
                'int' => (int) ($row[$c->key] ?? 0),
                'qty' => Dec::parse((string) ($row[$c->key] ?? '0'), Dec::QTY),
                default => Dec::parse((string) ($row[$c->key] ?? '0'), Dec::MONEY),
            };
        }
    }

    /** @param  array<string, int>  $acc @param  array<int, Col>  $cols @return array<string, string> */
    private function finish(ReportDef $def, array $acc, array $cols): array
    {
        $out = [];
        foreach ($cols as $c) {
            if ($c->sum) {
                $out[$c->key] = match ($c->type) {
                    'int' => (string) $acc[$c->key],
                    'qty' => Dec::fmt($acc[$c->key], Dec::QTY),
                    default => Dec::fmt($acc[$c->key], Dec::MONEY),
                };
            }
        }
        if ($def->derive !== null) {
            $out = array_intersect_key(($def->derive)($out) + $out, array_flip(array_map(fn (Col $c) => $c->key, $cols)));
        }

        return $out;
    }

    /**
     * @param  array<int, Col>  $cols
     * @param  array<string, string>  $now
     * @param  array<string, string>  $before
     * @return array<string, array{change: string, percent: string|null}>
     */
    private function delta(array $cols, array $now, array $before): array
    {
        $out = [];
        foreach ($cols as $c) {
            if (! isset($now[$c->key])) {
                continue;
            }
            $scale = $c->type === 'qty' ? Dec::QTY : ($c->type === 'int' ? 0 : Dec::MONEY);
            $a = $c->type === 'int' ? (int) $now[$c->key] : Dec::parse($now[$c->key], $scale);
            $b = $c->type === 'int' ? (int) ($before[$c->key] ?? 0) : Dec::parse($before[$c->key] ?? '0', $scale);
            $change = $c->type === 'int' ? (string) ($a - $b) : Dec::fmt($a - $b, $scale);
            $out[$c->key] = ['change' => $change, 'percent' => $b === 0 ? null : Dec::fmt(Dec::div(($a - $b) * 1000, abs($b)), 1)];
        }

        return $out;
    }
}
