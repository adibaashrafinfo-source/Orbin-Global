import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, Boxes, Layers, PackageX, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, DataTable, EmptyState, Input, KpiCard, PageHeader, Pagination, Select } from '@/design/components';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { STOCK_KEYS, stockApi } from '../stock/api';
import { fmtQty, trimCost } from '../stock/format';
import { useWarehouses } from '../stock/hooks';
import type { OverviewRow } from '../stock/types';
import { InventoryTabs } from './InventoryTabs';

const ALL = '__all__';
const STATUSES = ['ok', 'low', 'out', 'over', 'dead'] as const;

/** FR-401 / FR-407: stock per variant with the KPI strip, alerts and filters. */
export function OverviewPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const warehouses = useWarehouses();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [warehouse, setWarehouse] = useState('');
  const q = useDebouncedValue(search, 250);
  const params = { page, per_page: perPage, search: q, status, warehouse_id: warehouse };
  const query = useQuery({ queryKey: [...STOCK_KEYS.inventory, 'overview', params], queryFn: () => stockApi.overview(params), placeholderData: (prev) => prev });
  const kpis = query.data?.meta.kpis;
  const hasCost = kpis?.value !== undefined;

  const columns = useMemo<ColumnDef<OverviewRow, unknown>[]>(() => {
    const cols: ColumnDef<OverviewRow, unknown>[] = [
      { id: 'sku', header: t('products.fields.sku'), cell: ({ row }) => <code className="text-xs font-semibold">{row.original.sku}</code> },
      { id: 'name', header: t('inventory.product'), cell: ({ row }) => <div><div className="font-medium">{row.original.product_name}</div><div className="text-xs text-ink-muted">{row.original.variant_name}</div></div> },
      { id: 'on_hand', header: t('products.fields.onHand'), cell: ({ row }) => <span className="tabular font-semibold">{fmtQty(row.original.on_hand)}</span> },
      { id: 'reserved', header: t('inventory.reserved'), cell: ({ row }) => <span className="tabular text-ink-muted">{fmtQty(row.original.reserved)}</span> },
      { id: 'level', header: t('products.fields.reorderLevel'), cell: ({ row }) => <span className="tabular text-ink-muted">{Number(row.original.reorder_level) > 0 ? fmtQty(row.original.reorder_level) : '—'}</span> },
    ];
    if (hasCost) {
      cols.push({ id: 'cost', header: t('inventory.avgCost'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{trimCost(row.original.avg_cost)}</span> });
      cols.push({ id: 'value', header: t('inventory.value'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{formatMoney(row.original.stock_value ?? '0')}</span> });
    }
    cols.push({
      id: 'status', header: t('common.status'),
      cell: ({ row }) => (
        <span className="flex flex-wrap gap-1">
          <Badge tone={row.original.status === 'ok' ? 'success' : row.original.status === 'low' ? 'orange' : row.original.status === 'out' ? 'danger' : 'warning'}>{t(`inventory.stockStatus.${row.original.status}`)}</Badge>
          {row.original.is_dead === 1 && <Badge tone="neutral">{t('inventory.stockStatus.dead')}</Badge>}
        </span>
      ),
    });
    return cols;
  }, [t, hasCost]);

  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: query.error instanceof ApiError ? query.error.requestId : undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.inventory')} description={t('inventory.subtitle')} crumbs={[{ label: t('nav.inventory') }]} />
      <InventoryTabs />
      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <KpiCard label={t('inventory.kpi.skus')} value={String(kpis?.skus ?? 0)} numeric={kpis?.skus ?? 0} icon={<Layers className="h-4 w-4" aria-hidden="true" />} />
        <KpiCard label={t('inventory.kpi.units')} value={fmtQty(kpis?.units ?? '0')} icon={<Boxes className="h-4 w-4" aria-hidden="true" />} />
        {hasCost && <KpiCard label={t('inventory.kpi.value')} value={formatMoney(kpis?.value ?? '0')} icon={<Wallet className="h-4 w-4" aria-hidden="true" />} />}
        <KpiCard label={t('inventory.kpi.low')} value={String(kpis?.low ?? 0)} numeric={kpis?.low ?? 0} icon={<AlertTriangle className="h-4 w-4" aria-hidden="true" />} />
        <KpiCard label={t('inventory.kpi.out')} value={String(kpis?.out ?? 0)} numeric={kpis?.out ?? 0} icon={<PackageX className="h-4 w-4" aria-hidden="true" />} />
      </div>
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-64"><Input label={t('common.search')} type="search" placeholder={t('products.searchPlaceholder')} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} /></div>
        <div className="w-48"><Select label={t('inventory.warehouse')} value={warehouse || ALL} onChange={(v) => { setWarehouse(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, ...(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))]} /></div>
        <div className="w-48"><Select label={t('common.status')} value={status || ALL} onChange={(v) => { setStatus(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, { value: 'in', label: t('inventory.stockStatus.in') }, ...STATUSES.map((s) => ({ value: s, label: t(`inventory.stockStatus.${s}`) }))]} /></div>
      </div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(r) => String(r.variant_id)} onRowClick={(r) => navigate(`/inventory/card/${r.variant_id}`)} compact caption={t('nav.inventory')} empty={<EmptyState body={t('inventory.empty')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
    </>
  );
}
