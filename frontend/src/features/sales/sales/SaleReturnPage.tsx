import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { Badge, Card, CardBody, ErrorState, PageHeader, PageSkeleton } from '@/design/components';
import { fmtQty } from '@/features/stock/format';
import { http, type Envelope } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { fmtDateTime, SALE_TONE } from '../format';
import { returnApi } from '../api';
import { SalesTabs } from '../SalesTabs';
import type { SaleReturn } from '../types';

interface ReturnDetail extends SaleReturn {
  items?: { id: number; sale_item_id: number; qty: string; unit_price: string; tax_amount: string; line_total: string; condition: string }[];
}

/** One sale return: what came back, in what condition, and where the money went. */
export function SaleReturnPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const query = useQuery({ queryKey: ['sale-returns', id], queryFn: () => http.get<Envelope<ReturnDetail>>(`/sale-returns/${id}`).then((r) => r.data.data) });
  if (query.isLoading) return <PageSkeleton />;
  if (query.isError || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const r = query.data;
  return (
    <>
      <PageHeader title={r.return_no} description={fmtDateTime(r.return_date, i18n.language)} crumbs={[{ label: t('nav.sales'), to: '/sales' }, { label: t('sales.tabs.returns'), to: '/sales/returns' }, { label: r.return_no }]} />
      <SalesTabs />
      <Card><CardBody>
        <div className="mb-3 flex flex-wrap items-center gap-3"><Badge tone={SALE_TONE[r.status] ?? 'neutral'}>{t(`sales.returnStatus.${r.status}`)}</Badge><Link className="text-sm font-semibold text-navy-800 hover:underline" to={`/sales/${r.sale_id}`}>{r.invoice_no}</Link>{r.status === 'posted' && <a className="text-sm font-semibold text-navy-800 hover:underline" href={returnApi.pdfUrl(r.id)} target="_blank" rel="noopener noreferrer">{t('sales.creditNote')}</a>}</div>
        <table className="w-full text-sm"><caption className="sr-only">{t('sales.returnLines')}</caption>
          <thead><tr className="text-xs text-ink-muted"><th scope="col" className="py-1 text-start font-medium">{t('sales.condition')}</th><th scope="col" className="text-end font-medium">{t('sales.qty')}</th><th scope="col" className="text-end font-medium">{t('sales.vat')}</th><th scope="col" className="text-end font-medium">{t('sales.amount')}</th></tr></thead>
          <tbody>{(r.items ?? []).map((i) => <tr key={i.id} className="border-t border-navy-100"><td className="py-2">{t(`sales.conditions.${i.condition}`)}</td><td className="tabular text-end">{fmtQty(i.qty)}</td><td className="tabular text-end">{formatMoney(i.tax_amount, { symbol: false })}</td><td className="tabular text-end font-semibold">{formatMoney(i.line_total, { symbol: false })}</td></tr>)}</tbody></table>
        <dl className="ms-auto mt-4 w-full max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">{t('sales.refunded')}</dt><dd className="tabular font-semibold">{formatMoney(r.refund_total)}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">{t('sales.credited')}</dt><dd className="tabular font-semibold">{formatMoney(r.ledger_credit_total)}</dd></div>
        </dl>
        {r.note && <p className="mt-3 text-sm text-ink-muted">{r.note}</p>}
      </CardBody></Card>
    </>
  );
}
