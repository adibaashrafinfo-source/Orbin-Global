<?php

namespace App\Reports\Defs;

use App\Reports\CarbonLocal;
use App\Reports\Col;
use App\Reports\Q;
use App\Reports\ReportDef;
use App\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;

/** VAT collected (summary only) and the audit trail views. */
final class TaxAuditReports
{
    /** @return array<int, ReportDef> */
    public static function defs(): array
    {
        $audit = ['audit.view'];

        return [
            new ReportDef('vat-summary', 'VAT collected summary', 'tax', ['reports.view_all'], [
                new Col('period', 'Month'), new Col('rate', 'VAT rate %', 'percent'), new Col('taxable', 'Taxable amount', 'money', sum: true), new Col('vat', 'VAT collected', 'money', sum: true),
            ], static function (ReportFilters $f, $c) {
                $tax = 'ROUND(i.tax_amount * (i.qty - i.qty_returned) / i.qty, 2)';
                $q = Q::items($f, $c)->groupByRaw(Q::month('s.sale_date').', i.tax_rate_percent')->orderByRaw(Q::month('s.sale_date').', i.tax_rate_percent')
                    ->selectRaw(Q::month('s.sale_date').' as m, i.tax_rate_percent as rate, SUM('.Q::REV.') as taxable, SUM('.$tax.') as vat');
                foreach ($q->cursor() as $r) {
                    yield ['period' => (string) $r->m, 'rate' => number_format((float) $r->rate, 2, '.', ''), 'taxable' => Q::money($r->taxable), 'vat' => Q::money($r->vat)];
                }
            }, null, ['compare' => true, 'branch' => true]),
            new ReportDef('audit-price-changes', 'Price changes', 'audit', $audit, [
                new Col('time', 'Time', 'datetime'), new Col('user', 'User'), new Col('sku', 'SKU'), new Col('change', 'Change'),
            ], static function (ReportFilters $f) {
                $q = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->leftJoin('product_variants as v', fn ($j) => $j->on('v.id', '=', 'a.entity_id')->where('a.entity_table', '=', 'product_variants'))
                    ->where('a.action', 'price_change')->where('a.occurred_at', '>=', $f->fromUtc())->where('a.occurred_at', '<', $f->toUtcExclusive())->orderByDesc('a.occurred_at')->select(['a.occurred_at', 'a.before', 'a.after', 'u.full_name', 'v.sku']);
                foreach ($q->cursor() as $r) {
                    $before = (array) json_decode((string) $r->before, true);
                    $parts = [];
                    foreach ((array) json_decode((string) $r->after, true) as $k => $v) {
                        $parts[] = $k.': '.($before[$k] ?? '—').' → '.(is_scalar($v) ? $v : json_encode($v));
                    }
                    yield ['time' => substr(CarbonLocal::at((string) $r->occurred_at), 0, 16), 'user' => (string) ($r->full_name ?? '—'), 'sku' => (string) ($r->sku ?? ''), 'change' => implode('; ', $parts)];
                }
            }),
            new ReportDef('audit-overrides', 'Overrides and approvals', 'audit', $audit, [
                new Col('time', 'Time', 'datetime'), new Col('user', 'User'), new Col('action', 'Action'), new Col('entity', 'Document'), new Col('approver', 'Approved by'), new Col('reason', 'Reason'),
            ], static function (ReportFilters $f) {
                $q = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->leftJoin('users as ap', 'ap.id', '=', 'a.approver_id')->whereNotNull('a.approver_id')
                    ->where('a.occurred_at', '>=', $f->fromUtc())->where('a.occurred_at', '<', $f->toUtcExclusive())->orderByDesc('a.occurred_at')->select(['a.occurred_at', 'a.action', 'a.entity_table', 'a.entity_id', 'a.reason', 'u.full_name', 'ap.full_name as approver']);
                foreach ($q->cursor() as $r) {
                    yield ['time' => substr(CarbonLocal::at((string) $r->occurred_at), 0, 16), 'user' => (string) ($r->full_name ?? '—'), 'action' => str_replace('_', ' ', (string) $r->action), 'entity' => $r->entity_table.' #'.$r->entity_id,
                        'approver' => (string) $r->approver, 'reason' => (string) ($r->reason ?? '')];
                }
            }),
            new ReportDef('audit-login', 'Login history', 'audit', $audit, [
                new Col('time', 'Time', 'datetime'), new Col('user', 'User'), new Col('event', 'Event'), new Col('ip', 'IP address'),
            ], static function (ReportFilters $f) {
                $q = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->whereIn('a.action', ['login', 'login_failed', 'logout'])->where('a.occurred_at', '>=', $f->fromUtc())
                    ->where('a.occurred_at', '<', $f->toUtcExclusive())->orderByDesc('a.occurred_at')->select(['a.occurred_at', 'a.action', 'a.ip', 'a.after', 'u.full_name']);
                foreach ($q->cursor() as $r) {
                    $who = $r->full_name ?? ((array) json_decode((string) $r->after, true))['email'] ?? '—';
                    yield ['time' => substr(CarbonLocal::at((string) $r->occurred_at), 0, 16), 'user' => (string) $who, 'event' => str_replace('_', ' ', (string) $r->action), 'ip' => (string) ($r->ip ?? '')];
                }
            }),
        ];
    }
}
