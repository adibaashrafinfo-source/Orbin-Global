import { useQuery } from '@tanstack/react-query';
import { FileText } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';
import { Badge, Card, CardBody, ErrorState, KpiCard, PageHeader, PageSkeleton } from '@/design/components';
import { formatMoney } from '@/lib/money';
import { SALES_KEYS, shiftApi } from '../api';
import { fmtDateTime } from '../format';
import { SalesTabs } from '../SalesTabs';
import { ShiftActions } from './ShiftActions';

/** One shift: its takings by method, the cash reconciliation and the Z-report once closed. */
export function ShiftPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const query = useQuery({ queryKey: [...SALES_KEYS.shifts, id], queryFn: () => shiftApi.show(id) });
  if (query.isLoading) return <PageSkeleton />;
  if (query.isError || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const s = query.data;
  const tot = s.totals;
  return (
    <>
      <PageHeader title={s.shift_no} description={`${s.cashier ?? ''} · ${fmtDateTime(s.opened_at, i18n.language)}`} crumbs={[{ label: t('nav.sales'), to: '/sales' }, { label: t('sales.tabs.shifts'), to: '/shifts' }, { label: s.shift_no }]}
        actions={s.status === 'closed' ? (s.has_z_report ? <a href={shiftApi.zReportUrl(s.id)} target="_blank" rel="noopener noreferrer" className="inline-flex h-10 items-center gap-2 rounded-button border border-navy-200 bg-surface px-4 text-sm font-semibold text-navy-900 hover:bg-navy-50 focus-visible:focus-ring-light"><FileText className="h-4 w-4" aria-hidden="true" />{t('shifts.zReport')}</a> : undefined) : <ShiftActions shift={s} />} />
      <SalesTabs />
      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard label={t('shifts.salesCount')} value={String(tot?.sales_count ?? 0)} />
        <KpiCard label={t('sales.kpiTotal')} value={formatMoney(tot?.sales_total ?? '0')} />
        <KpiCard label={t('shifts.expected')} value={formatMoney(s.expected_cash ?? tot?.expected_cash ?? '0')} />
        <KpiCard label={t('shifts.variance')} value={s.variance === null ? '—' : formatMoney(s.variance)} />
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardBody>
          <h3 className="mb-2 text-sm font-semibold text-navy-900">{t('shifts.byMethod')}</h3>
          {Object.keys(tot?.by_method ?? {}).length === 0 ? <p className="text-sm text-ink-muted">{t('shifts.noPayments')}</p> : (
            <table className="w-full text-sm"><caption className="sr-only">{t('shifts.byMethod')}</caption><tbody>{Object.entries(tot?.by_method ?? {}).map(([m, v]) => <tr key={m} className="border-t border-navy-100 first:border-0"><td className="py-1.5">{t(`pos.pay.methods.${m}`)}</td><td className="tabular text-end text-ink-muted">{v.count}</td><td className="tabular text-end font-semibold">{formatMoney(v.total)}</td></tr>)}</tbody></table>
          )}
        </CardBody></Card>
        <Card><CardBody>
          <h3 className="mb-2 text-sm font-semibold text-navy-900">{t('shifts.cashDrawer')} <Badge tone={s.status === 'open' ? 'orange' : 'neutral'}>{t(`shifts.status.${s.status}`)}</Badge></h3>
          <dl className="space-y-1 text-sm">
            {([['openingFloat', tot?.opening_float], ['cashSales', tot?.cash_sales], ['cashCollections', tot?.cash_collections], ['cashRefunds', tot?.cash_refunds], ['cashInLabel', tot?.cash_in], ['cashOutLabel', tot?.cash_out], ['cashPaidOut', tot?.cash_paid_out], ['expected', s.expected_cash ?? tot?.expected_cash], ['counted', s.counted_cash]] as const).map(([k, v]) => <div key={k} className="flex justify-between"><dt className="text-ink-muted">{t(`shifts.${k}`)}</dt><dd className="tabular font-medium">{v ? formatMoney(v) : '—'}</dd></div>)}
          </dl>
          {s.note && <p className="mt-3 text-sm text-ink-muted">{s.note}</p>}
        </CardBody></Card>
      </div>
    </>
  );
}
