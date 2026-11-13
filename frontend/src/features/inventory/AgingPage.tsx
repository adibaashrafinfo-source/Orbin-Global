import { useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardBody, EmptyState, PageHeader, Select, Skeleton } from '@/design/components';
import { formatMoney } from '@/lib/money';
import { STOCK_KEYS, stockApi } from '../stock/api';
import { fmtQty } from '../stock/format';
import { useWarehouses } from '../stock/hooks';
import { InventoryTabs } from './InventoryTabs';

const ALL = '__all__';
const BUCKETS = ['0-30', '31-60', '61-90', '91-180', '180+'] as const;

/** FR-411: how long the stock on the shelf has been there. */
export function AgingPage() {
  const { t } = useTranslation();
  const warehouses = useWarehouses();
  const [warehouse, setWarehouse] = useState('');
  const params = { warehouse_id: warehouse };
  const query = useQuery({ queryKey: [...STOCK_KEYS.inventory, 'aging', params], queryFn: () => stockApi.aging(params) });
  const data = query.data;
  const max = Math.max(1, ...BUCKETS.map((b) => Number(data?.buckets[b]?.qty ?? 0)));
  return (
    <>
      <PageHeader title={t('nav.inventory')} description={t('inventory.agingSubtitle')} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.aging') }]} />
      <InventoryTabs />
      <div className="mb-4 flex items-end gap-3">
        <div className="w-48"><Select label={t('inventory.warehouse')} value={warehouse || ALL} onChange={(v) => setWarehouse(v === ALL ? '' : v)} options={[{ value: ALL, label: t('common.all') }, ...(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))]} /></div>
        <a className="ms-auto inline-flex h-9 items-center gap-2 rounded-input border border-navy-200 bg-surface px-3 text-sm font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light" href={stockApi.csvUrl('inventory/aging', params)} download><Download className="h-4 w-4" aria-hidden="true" />{t('products.export')}</a>
      </div>
      {query.isLoading || !data ? <Skeleton className="h-48 w-full" /> : (
        <>
          <div className="mb-5 grid gap-3 sm:grid-cols-5">
            {BUCKETS.map((b) => (
              <Card key={b}><CardBody>
                <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">{t('inventory.days', { range: b })}</p>
                <p className="tabular mt-1 font-heading text-xl font-bold text-navy-900">{fmtQty(data.buckets[b]?.qty)}</p>
                {data.buckets[b]?.value !== undefined && <p className="tabular text-xs text-ink-muted">{formatMoney(data.buckets[b]?.value ?? '0')}</p>}
                <div className="mt-2 h-1.5 rounded-pill bg-navy-100" aria-hidden="true"><div className="h-full rounded-pill bg-navy-700" style={{ width: `${(Number(data.buckets[b]?.qty ?? 0) / max) * 100}%` }} /></div>
              </CardBody></Card>
            ))}
          </div>
          {data.rows.length === 0 ? <EmptyState body={t('inventory.empty')} /> : (
            <div className="overflow-x-auto rounded-card border border-navy-100 bg-surface">
              <table className="w-full min-w-[640px] text-sm">
                <caption className="sr-only">{t('inventory.tabs.aging')}</caption>
                <thead className="bg-navy-900 text-xs uppercase tracking-wide text-surface"><tr><th scope="col" className="px-3 py-2 text-start">{t('products.fields.sku')}</th>{BUCKETS.map((b) => <th key={b} scope="col" className="px-3 py-2 text-end">{b}</th>)}</tr></thead>
                <tbody>{data.rows.map((r) => <tr key={`${r.variant_id}`} className="border-t border-navy-100 odd:bg-navy-50/40"><th scope="row" className="px-3 py-2 text-start font-normal"><code className="text-xs font-semibold">{r.sku}</code><div className="text-xs text-ink-muted">{r.variant_name}</div></th>{BUCKETS.map((b) => <td key={b} className="tabular px-3 py-2 text-end">{Number(r.buckets[b]) > 0 ? fmtQty(r.buckets[b]) : <span className="text-ink-muted">—</span>}</td>)}</tr>)}</tbody>
              </table>
            </div>
          )}
        </>
      )}
    </>
  );
}
