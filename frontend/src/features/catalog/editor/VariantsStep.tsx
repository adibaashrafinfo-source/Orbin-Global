import type { ColumnDef } from '@tanstack/react-table';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Copy, Layers, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, ConfirmDialog, DataTable, EmptyState, IconButton, toast } from '@/design/components';
import { StopClick } from '../StopClick';
import { formatMoney } from '@/lib/money';
import { KEYS, variantApi } from '../api';
import type { Variant } from '../types';
import { useApiError } from '../useApiError';
import { CloneVariantDialog } from './CloneVariantDialog';
import { MatrixDialog } from './MatrixDialog';
import type { StepProps } from './steps';
import { VariantDrawer } from './VariantDrawer';

/** Step 2. Variants of the product with the matrix generator (FR-303/304). */
export function VariantsStep({ product, canManage, canPrice, canCost }: StepProps) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const variants = product.variants ?? [];
  const [editId, setEditId] = useState<number | 'new' | null>(null);
  const [cloneId, setCloneId] = useState<number | null>(null);
  const [delId, setDelId] = useState<number | null>(null);
  const [matrix, setMatrix] = useState(false);
  const byId = (id: number | null) => variants.find((v) => v.id === id) ?? null;
  const refresh = () => void client.invalidateQueries({ queryKey: KEYS.product(product.id) });
  const remove = useMutation({ mutationFn: (id: number) => variantApi.remove(id), onSuccess: () => { refresh(); toast.success(t('masterData.deleted')); setDelId(null); }, onError: (e) => { setDelId(null); onError(e); } });

  const columns = useMemo<ColumnDef<Variant, unknown>[]>(() => {
    const cols: ColumnDef<Variant, unknown>[] = [
      { id: 'sku', header: t('products.fields.sku'), cell: ({ row }) => <code className="text-xs font-semibold">{row.original.sku}</code> },
      { id: 'name', header: t('products.fields.variantName'), cell: ({ row }) => row.original.variant_name ?? '—' },
      { id: 'position', header: t('products.fields.position'), cell: ({ row }) => t(`products.positions.${row.original.position}`) },
      { id: 'size', header: t('products.fields.sizeLabel'), cell: ({ row }) => row.original.size_label ?? '—' },
      { id: 'barcode', header: t('products.fields.barcode'), cell: ({ row }) => row.original.barcode ? <code className="text-xs">{row.original.barcode}</code> : <span className="text-ink-subtle">—</span> },
      { id: 'price', header: t('products.fields.retail_price'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{formatMoney(row.original.retail_price)}</span> },
      { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={row.original.is_discontinued ? 'neutral' : row.original.is_active ? 'success' : 'warning'}>{row.original.is_discontinued ? t('products.fields.discontinued') : row.original.is_active ? t('common.active') : t('common.inactive')}</Badge> },
    ];
    if (canManage) cols.push({
      id: 'actions', header: () => <span className="sr-only">{t('common.actions')}</span>,
      cell: ({ row }) => (
        <StopClick className="flex justify-end gap-1">
          <IconButton label={t('common.edit')} onClick={() => setEditId(row.original.id)}><Pencil className="h-4 w-4" aria-hidden="true" /></IconButton>
          <IconButton label={t('products.cloneVariant')} onClick={() => setCloneId(row.original.id)}><Copy className="h-4 w-4" aria-hidden="true" /></IconButton>
          <IconButton label={t('common.delete')} onClick={() => setDelId(row.original.id)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>
        </StopClick>
      ),
    });
    return cols;
  }, [t, canManage]);

  const actions = canManage ? (
    <div className="flex gap-2">
      <Button variant="secondary" leftIcon={<Layers className="h-4 w-4" aria-hidden="true" />} onClick={() => setMatrix(true)}>{t('products.matrix.open')}</Button>
      <Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditId('new')}>{t('products.addVariant')}</Button>
    </div>
  ) : null;

  return (
    <section aria-labelledby="variants-h">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 id="variants-h" className="font-heading text-lg font-bold text-navy-900">{t('products.variants')} <span className="text-sm font-normal text-ink-muted">({variants.length})</span></h2>
        {actions}
      </div>
      <DataTable columns={columns} data={variants} getRowId={(v) => String(v.id)} compact caption={t('products.variants')} onRowClick={canManage ? (v) => setEditId(v.id) : undefined} empty={<EmptyState body={t('products.variantsEmpty')} action={actions} />} />
      <VariantDrawer productId={product.id} variant={editId === 'new' ? 'new' : byId(editId)} canPrice={canPrice} canCost={canCost} onClose={() => setEditId(null)} />
      <CloneVariantDialog productId={product.id} variant={byId(cloneId)} onClose={() => setCloneId(null)} />
      <MatrixDialog productId={product.id} open={matrix} canPrice={canPrice} onClose={() => setMatrix(false)} />
      <ConfirmDialog open={delId !== null} onOpenChange={(o) => !o && setDelId(null)} danger title={t('products.deleteVariantTitle')} body={t('products.deleteVariantBody', { sku: byId(delId)?.sku ?? '' })} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (delId !== null) remove.mutate(delId); }} />
    </section>
  );
}
