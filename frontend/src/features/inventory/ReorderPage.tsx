import { useMutation, useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { ShoppingCart } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Button, Checkbox, DataTable, EmptyState, Input, PageHeader, Select, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { useApiError } from '@/features/catalog/useApiError';
import { STOCK_KEYS, stockApi } from '../stock/api';
import { fmtQty } from '../stock/format';
import { useSuppliers } from '../stock/hooks';
import type { ReorderRow } from '../stock/types';
import { InventoryTabs } from './InventoryTabs';

const NONE = '__none__';

/** FR-408: what is at or below its reorder level, and one click to a draft purchase order per supplier. */
export function ReorderPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const navigate = useNavigate();
  const onError = useApiError();
  const suppliers = useSuppliers();
  const list = useQuery({ queryKey: [...STOCK_KEYS.inventory, 'reorder'], queryFn: () => stockApi.reorder() });
  const [picked, setPicked] = useState<Record<number, boolean>>({});
  const [qty, setQty] = useState<Record<number, string>>({});
  const [supplier, setSupplier] = useState<Record<number, string>>({});
  const canOrder = can(['purchases.manage']);
  const rows = list.data ?? [];
  const supplierOf = (r: ReorderRow) => supplier[r.variant_id] ?? (r.supplier_id ? String(r.supplier_id) : NONE);
  const chosen = rows.filter((r) => picked[r.variant_id] && supplierOf(r) !== NONE);

  const make = useMutation({
    mutationFn: () => stockApi.createPurchaseOrders(chosen.map((r) => ({ variant_id: r.variant_id, qty: qty[r.variant_id] ?? r.suggested_qty, supplier_id: Number(supplierOf(r)) }))),
    onSuccess: (orders) => { toast.success(t('inventory.draftsCreated', { count: orders.length })); navigate(orders.length === 1 ? `/purchases/orders/${orders[0]?.id}` : '/purchases/orders'); },
    onError,
  });

  const columns = useMemo<ColumnDef<ReorderRow, unknown>[]>(() => [
    ...(canOrder ? [{ id: 'pick', header: () => <span className="sr-only">{t('common.actions')}</span>, cell: ({ row }: { row: { original: ReorderRow } }) => <Checkbox checked={Boolean(picked[row.original.variant_id])} onChange={(v) => setPicked((p) => ({ ...p, [row.original.variant_id]: v }))} aria-label={row.original.sku} /> }] : []),
    { id: 'sku', header: t('products.fields.sku'), cell: ({ row }) => <div><code className="text-xs font-semibold">{row.original.sku}</code><div className="text-xs text-ink-muted">{row.original.product_name}</div></div> },
    { id: 'on', header: t('products.fields.onHand'), cell: ({ row }) => <span className="tabular font-semibold">{fmtQty(row.original.on_hand)}</span> },
    { id: 'level', header: t('products.fields.reorderLevel'), cell: ({ row }) => <span className="tabular text-ink-muted">{fmtQty(row.original.reorder_level)}</span> },
    { id: 'qty', header: t('inventory.orderQty'), cell: ({ row }) => <Input aria-label={`${row.original.sku} ${t('inventory.orderQty')}`} className="w-28" inputMode="decimal" disabled={!canOrder} value={qty[row.original.variant_id] ?? fmtQty(row.original.suggested_qty)} onChange={(e) => setQty((q) => ({ ...q, [row.original.variant_id]: e.target.value }))} /> },
    { id: 'supplier', header: t('purchasing.supplier'), cell: ({ row }) => <div className="w-52"><Select aria-label={`${row.original.sku} ${t('purchasing.supplier')}`} disabled={!canOrder} value={supplierOf(row.original)} onChange={(v) => setSupplier((s) => ({ ...s, [row.original.variant_id]: v }))} options={[{ value: NONE, label: t('inventory.chooseSupplier') }, ...(suppliers.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))]} /></div> },
  // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [t, canOrder, picked, qty, supplier, suppliers.data]);

  return (
    <>
      <PageHeader title={t('nav.inventory')} description={t('inventory.reorderSubtitle')} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.reorder') }]}
        actions={canOrder ? <Button leftIcon={<ShoppingCart className="h-4 w-4" aria-hidden="true" />} disabled={chosen.length === 0} loading={make.isPending} onClick={() => make.mutate()}>{t('inventory.createDrafts', { count: chosen.length })}</Button> : undefined} />
      <InventoryTabs />
      <DataTable columns={columns} data={rows} loading={list.isLoading} getRowId={(r) => String(r.variant_id)} compact caption={t('inventory.tabs.reorder')} empty={<EmptyState body={t('inventory.emptyReorder')} />} />
    </>
  );
}
