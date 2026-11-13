<?php

namespace App\Reports;

use App\Support\Dec;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The few definitions every report shares, so the same money is counted the same way everywhere (dashboard = report = invoices).
 *
 * Sold invoices are completed, partly returned and returned ones; a void invoice never counts. Item figures are net of what was
 * returned and without VAT: revenue = (line total − VAT) × (qty − returned) ÷ qty; cost = (qty − returned) × the cost on the line.
 */
final class Q
{
    public const SOLD = ['completed', 'partially_returned', 'returned'];

    /** Dhaka is UTC+6 all year; datetime columns are UTC. */
    public static function day(string $col): string
    {
        return "DATE(DATE_ADD($col, INTERVAL 6 HOUR))";
    }

    public static function month(string $col): string
    {
        return "DATE_FORMAT(DATE_ADD($col, INTERVAL 6 HOUR), '%Y-%m')";
    }

    public const NET_QTY = '(i.qty - i.qty_returned)';

    public const REV = 'ROUND((i.line_total - i.tax_amount) * (i.qty - i.qty_returned) / i.qty, 2)';

    public const COGS = 'ROUND((i.qty - i.qty_returned) * i.unit_cost, 2)';

    /** Units sold, counting a bundle once (its component rows carry cost only). */
    public const UNITS = 'CASE WHEN i.bundle_parent_line IS NULL THEN i.qty - i.qty_returned ELSE 0 END';

    /** Sold invoices in the range, for this person's scope. */
    public static function invoices(ReportFilters $f, ReportContext $c): Builder
    {
        return DB::table('sales as s')->whereIn('s.status', self::SOLD)->whereNull('s.deleted_at')
            ->where('s.sale_date', '>=', $f->fromUtc())->where('s.sale_date', '<', $f->toUtcExclusive())
            ->when($f->branchId, fn ($q) => $q->where('s.branch_id', $f->branchId))
            ->when($c->ownOnly, fn ($q) => $q->where('s.cashier_id', $c->user->id));
    }

    /** The same invoices with their lines. */
    public static function items(ReportFilters $f, ReportContext $c): Builder
    {
        return self::invoices($f, $c)->join('sale_items as i', fn ($j) => $j->on('i.sale_id', '=', 's.id')->whereNull('i.deleted_at'));
    }

    public static function money(mixed $v): string
    {
        return Dec::fmt(Dec::parse((string) ($v ?? '0'), Dec::MONEY), Dec::MONEY);
    }

    public static function qty(mixed $v): string
    {
        return Dec::fmt(Dec::parse((string) ($v ?? '0'), Dec::QTY), Dec::QTY);
    }

    /** a ÷ b as a percentage with one decimal, "0.0" when b is zero. Paisa in, text out. */
    public static function pct(mixed $a, mixed $b): string
    {
        $den = Dec::parse((string) ($b ?? '0'), Dec::MONEY);
        if ($den === 0) {
            return '0.0';
        }
        $num = Dec::parse((string) ($a ?? '0'), Dec::MONEY);

        return Dec::fmt(Dec::div($num * 1000, $den), 1);
    }
}
