import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, DataTable, EmptyState, Input, PageHeader, Pagination, Select } from '@/design/components';
import { ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { STOCK_KEYS, stockApi } from '../stock/api';
import { fmtDelta, fmtQty, trimCost } from '../stock/format';
import { useWarehouses } from '../stock/hooks';
import { MOVEMENT_TYPES, type MovementRow } from '../stock/types';
import { InventoryTabs } from './InventoryTabs';

const ALL = '__all__';

/** FR-402: the stock ledger. Every posting in one list, newest first. */
export function MovementsPage() {
  const { t, i18n } = useTranslation();
  const warehouses = useWarehouses();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [ref, setRef] = useState('');
  const [type, setType] = useState('');
  const [warehouse, setWarehouse] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const refQ = useDebouncedValue(ref, 250);
  const params = { page, per_page: perPage, ref_no: refQ, 'type[]': type, warehouse_id: warehouse, from, to };
  const query = useQuery({ queryKey: [...STOCK_KEYS.inventory, 'movements', params], queryFn: () => stockApi.movements(params), placeholderData: (prev) => prev });
  const hasCost = (query.data?.data[0]?.unit_cost ?? undefined) !== undefined;
  const columns = useMemo<ColumnDef<MovementRow, unknown>[]>(() => {
    const cols: ColumnDef<MovementRow, unknown>[] = [
      { id: 'when', header: t('inventory.when'), cell: ({ row }) => <span className="whitespace-nowrap">{formatDateTime(row.original.occurred_at, i18n.language)}</span> },
      { id: 'type', header: t('inventory.type'), cell: ({ row }) => <Badge tone={Number(row.original.qty_delta) > 0 ? 'success' : 'neutral'}>{t(`inventory.movementTypes.${row.original.movement_type}`)}</Badge> },
      { id: 'sku', header: t('products.fields.sku'), cell: ({ row }) => <code className="text-xs font-semibold">{row.original.sku}</code> },
      { id: 'warehouse', header: t('inventory.warehouse'), cell: ({ row }) => row.original.warehouse },
      { id: 'qty', header: t('inventory.qty'), cell: ({ row }) => <span className={`tabular font-semibold ${Number(row.original.qty_delta) < 0 ? 'text-danger' : 'text-success'}`}>{fmtDelta(row.original.qty_delta)}</span> },
      { id: 'balance', header: t('inventory.balance'), cell: ({ row }) => <span className="tabular">{fmtQty(row.original.balance_after)}</span> },
    ];
    if (hasCost) cols.push({ id: 'cost', header: t('inventory.unitCost'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{trimCost(row.original.unit_cost)}</span> });
    cols.push({ id: 'ref', header: t('inventory.reference'), cell: ({ row }) => row.original.ref_no ?? '—' }, { id: 'user', header: t('inventory.user'), cell: ({ row }) => row.original.user ?? '—' });
    return cols;
  }, [t, i18n.language, hasCost]);
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.inventory')} description={t('inventory.movementsSubtitle')} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.movements') }]} />
      <InventoryTabs />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-48"><Input label={t('inventory.reference')} type="search" value={ref} onChange={(e) => { setRef(e.target.value); setPage(1); }} /></div>
        <div className="w-52"><Select label={t('inventory.type')} value={type || ALL} onChange={(v) => { setType(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, ...MOVEMENT_TYPES.map((m) => ({ value: m, label: t(`inventory.movementTypes.${m}`) }))]} /></div>
        <div className="w-44"><Select label={t('inventory.warehouse')} value={warehouse || ALL} onChange={(v) => { setWarehouse(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, ...(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))]} /></div>
        <div className="w-40"><Input label={t('inventory.from')} type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} /></div>
        <div className="w-40"><Input label={t('inventory.to')} type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} /></div>
        <a className="ms-auto inline-flex h-9 items-center gap-2 rounded-input border border-navy-200 bg-surface px-3 text-sm font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light" href={stockApi.csvUrl('inventory/movements', { warehouse_id: warehouse, 'type[]': type, ref_no: refQ, from, to })} download><Download className="h-4 w-4" aria-hidden="true" />{t('products.export')}</a>
      </div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(r) => String(r.id)} compact caption={t('inventory.tabs.movements')} empty={<EmptyState body={t('inventory.emptyMovements')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
    </>
  );
}
