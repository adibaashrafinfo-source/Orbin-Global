import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable, EmptyState, Input, KpiCard, PageHeader, Select } from '@/design/components';
import { formatMoney } from '@/lib/money';
import { STOCK_KEYS, stockApi } from '../stock/api';
import { fmtQty, trimCost } from '../stock/format';
import { useWarehouses } from '../stock/hooks';
import type { ValuationResult } from '../stock/types';
import { InventoryTabs } from './InventoryTabs';

const ALL = '__all__';

/** FR-409: what the stock was worth at the end of any day, rebuilt from the movements. */
export function ValuationPage() {
  const { t } = useTranslation();
  const warehouses = useWarehouses();
  const [asOf, setAsOf] = useState('');
  const [warehouse, setWarehouse] = useState('');
  const params = { as_of: asOf, warehouse_id: warehouse };
  const query = useQuery({ queryKey: [...STOCK_KEYS.inventory, 'valuation', params], queryFn: () => stockApi.valuation(params) });
  const columns = useMemo<ColumnDef<ValuationResult['rows'][number], unknown>[]>(() => [
    { id: 'sku', header: t('products.fields.sku'), cell: ({ row }) => <code className="text-xs font-semibold">{row.original.sku}</code> },
    { id: 'name', header: t('inventory.product'), cell: ({ row }) => <div><div className="font-medium">{row.original.product_name}</div><div className="text-xs text-ink-muted">{row.original.variant_name}</div></div> },
    { id: 'qty', header: t('inventory.qty'), cell: ({ row }) => <span className="tabular">{fmtQty(row.original.qty)}</span> },
    { id: 'avg', header: t('inventory.avgCost'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{trimCost(row.original.avg_cost)}</span> },
    { id: 'value', header: t('inventory.value'), cell: ({ row }) => <span className="tabular whitespace-nowrap font-semibold">{formatMoney(row.original.value)}</span> },
  ], [t]);
  return (
    <>
      <PageHeader title={t('nav.inventory')} description={t('inventory.valuationSubtitle')} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.valuation') }]} />
      <InventoryTabs />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-44"><Input label={t('inventory.asOf')} type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} hint={t('inventory.asOfHint')} /></div>
        <div className="w-48"><Select label={t('inventory.warehouse')} value={warehouse || ALL} onChange={(v) => setWarehouse(v === ALL ? '' : v)} options={[{ value: ALL, label: t('common.all') }, ...(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))]} /></div>
        <div className="ms-auto flex items-end gap-3">
          <KpiCard label={t('inventory.totalValue', { date: query.data?.as_of ?? '' })} value={formatMoney(query.data?.total ?? '0')} />
          <a className="inline-flex h-9 items-center gap-2 rounded-input border border-navy-200 bg-surface px-3 text-sm font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light" href={stockApi.csvUrl('inventory/valuation', params)} download><Download className="h-4 w-4" aria-hidden="true" />{t('products.export')}</a>
        </div>
      </div>
      <DataTable columns={columns} data={query.data?.rows ?? []} loading={query.isLoading} getRowId={(r) => `${r.variant_id}-${r.warehouse_id}`} compact caption={t('inventory.tabs.valuation')} empty={<EmptyState body={t('inventory.empty')} />} />
    </>
  );
}
