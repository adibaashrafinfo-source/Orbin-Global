import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, DataTable, EmptyState, Input, KpiCard, PageHeader, Select } from '@/design/components';
import { formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useSuppliers } from '../stock/hooks';
import { STOCK_KEYS, stockApi } from '../stock/api';
import { fmtQty } from '../stock/format';
import type { BreakageRow } from '../stock/types';
import { InventoryTabs } from './InventoryTabs';

const ALL = '__all__';

/** FR-404: glass breaks. Every breakage and damage, by product, supplier and day, with what it cost. */
export function BreakagePage() {
  const { t, i18n } = useTranslation();
  const suppliers = useSuppliers();
  const [supplier, setSupplier] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const params = { supplier_id: supplier, from, to };
  const query = useQuery({ queryKey: [...STOCK_KEYS.inventory, 'breakage', params], queryFn: () => stockApi.breakage(params) });
  const hasCost = query.data?.meta.cost_impact !== undefined;
  const columns = useMemo<ColumnDef<BreakageRow, unknown>[]>(() => {
    const cols: ColumnDef<BreakageRow, unknown>[] = [
      { id: 'when', header: t('inventory.when'), cell: ({ row }) => <span className="whitespace-nowrap">{formatDateTime(row.original.occurred_at, i18n.language)}</span> },
      { id: 'type', header: t('inventory.type'), cell: ({ row }) => <Badge tone="warning">{t(`inventory.movementTypes.${row.original.movement_type}`)}</Badge> },
      { id: 'product', header: t('inventory.product'), cell: ({ row }) => <div><code className="text-xs font-semibold">{row.original.sku}</code><div className="text-xs text-ink-muted">{row.original.product_name}</div></div> },
      { id: 'qty', header: t('inventory.qty'), cell: ({ row }) => <span className="tabular">{fmtQty(row.original.qty)}</span> },
      { id: 'supplier', header: t('purchasing.supplier'), cell: ({ row }) => row.original.supplier ?? '—' },
      { id: 'user', header: t('inventory.user'), cell: ({ row }) => row.original.user ?? '—' },
      { id: 'ref', header: t('inventory.reference'), cell: ({ row }) => row.original.ref_no ?? '—' },
    ];
    if (hasCost) cols.push({ id: 'impact', header: t('inventory.costImpact'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{formatMoney(row.original.cost_impact ?? '0')}</span> });
    return cols;
  }, [t, i18n.language, hasCost]);
  return (
    <>
      <PageHeader title={t('nav.inventory')} description={t('inventory.breakageSubtitle')} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.breakage') }]} />
      <InventoryTabs />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-56"><Select label={t('purchasing.supplier')} value={supplier || ALL} onChange={(v) => setSupplier(v === ALL ? '' : v)} options={[{ value: ALL, label: t('common.all') }, ...(suppliers.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))]} /></div>
        <div className="w-40"><Input label={t('inventory.from')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
        <div className="w-40"><Input label={t('inventory.to')} type="date" value={to} onChange={(e) => setTo(e.target.value)} /></div>
        <div className="ms-auto flex gap-3"><KpiCard label={t('inventory.breakageCount')} value={String(query.data?.meta.count ?? 0)} />{hasCost && <KpiCard label={t('inventory.costImpact')} value={formatMoney(query.data?.meta.cost_impact ?? '0')} />}</div>
      </div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} getRowId={(r) => String(r.id)} compact caption={t('inventory.tabs.breakage')} empty={<EmptyState body={t('inventory.emptyBreakage')} />} />
    </>
  );
}
