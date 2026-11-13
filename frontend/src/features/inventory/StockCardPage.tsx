import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';
import { Badge, DataTable, EmptyState, ErrorState, Input, PageHeader, PageSkeleton, Select } from '@/design/components';
import { formatDateTime } from '@/lib/format';
import { STOCK_KEYS, stockApi } from '../stock/api';
import { fmtDelta, fmtQty, trimCost } from '../stock/format';
import { useWarehouses } from '../stock/hooks';
import type { CardRow } from '../stock/types';

const ALL = '__all__';

/** FR-410: one variant's movements, oldest first, with the running balance. */
export function StockCardPage() {
  const { t, i18n } = useTranslation();
  const { variantId } = useParams();
  const id = Number(variantId);
  const warehouses = useWarehouses();
  const [warehouse, setWarehouse] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const params = { warehouse_id: warehouse, from, to };
  const query = useQuery({ queryKey: [...STOCK_KEYS.inventory, 'card', id, params], queryFn: () => stockApi.card(id, params), enabled: Number.isFinite(id) });
  const hasCost = (query.data?.rows[0]?.unit_cost ?? undefined) !== undefined;
  const columns = useMemo<ColumnDef<CardRow, unknown>[]>(() => {
    const cols: ColumnDef<CardRow, unknown>[] = [
      { id: 'when', header: t('inventory.when'), cell: ({ row }) => <span className="whitespace-nowrap">{formatDateTime(row.original.occurred_at, i18n.language)}</span> },
      { id: 'type', header: t('inventory.type'), cell: ({ row }) => <Badge tone={Number(row.original.qty_delta) > 0 ? 'success' : 'neutral'}>{t(`inventory.movementTypes.${row.original.movement_type}`)}</Badge> },
      { id: 'warehouse', header: t('inventory.warehouse'), cell: ({ row }) => row.original.warehouse },
      { id: 'qty', header: t('inventory.qty'), cell: ({ row }) => <span className={`tabular font-semibold ${Number(row.original.qty_delta) < 0 ? 'text-danger' : 'text-success'}`}>{fmtDelta(row.original.qty_delta)}</span> },
      { id: 'running', header: t('inventory.balance'), cell: ({ row }) => <span className="tabular font-semibold">{fmtQty(row.original.running)}</span> },
    ];
    if (hasCost) cols.push({ id: 'cost', header: t('inventory.unitCost'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{trimCost(row.original.unit_cost)}</span> });
    cols.push({ id: 'ref', header: t('inventory.reference'), cell: ({ row }) => row.original.ref_no ?? '—' }, { id: 'reason', header: t('inventory.reason'), cell: ({ row }) => row.original.reason ?? '—' });
    return cols;
  }, [t, i18n.language, hasCost]);

  if (query.isLoading) return <PageSkeleton />;
  if (query.error || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const card = query.data;
  return (
    <>
      <PageHeader title={card.variant.sku} description={card.variant.variant_name ?? undefined} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.stockCard') }]} />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-48"><Select label={t('inventory.warehouse')} value={warehouse || ALL} onChange={(v) => setWarehouse(v === ALL ? '' : v)} options={[{ value: ALL, label: t('common.all') }, ...(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))]} /></div>
        <div className="w-40"><Input label={t('inventory.from')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
        <div className="w-40"><Input label={t('inventory.to')} type="date" value={to} onChange={(e) => setTo(e.target.value)} /></div>
        <div className="ms-auto flex items-center gap-4 text-sm"><span>{t('inventory.opening')}: <strong className="tabular">{fmtQty(card.opening)}</strong></span><span>{t('inventory.closing')}: <strong className="tabular">{fmtQty(card.closing)}</strong></span>
          <a className="inline-flex h-9 items-center gap-2 rounded-input border border-navy-200 bg-surface px-3 font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light" href={stockApi.csvUrl(`inventory/stock-card/${id}`, params)} download><Download className="h-4 w-4" aria-hidden="true" />{t('products.export')}</a></div>
      </div>
      <DataTable columns={columns} data={card.rows} getRowId={(r) => String(r.id)} compact caption={t('inventory.stockCard')} empty={<EmptyState body={t('inventory.emptyMovements')} />} />
    </>
  );
}
