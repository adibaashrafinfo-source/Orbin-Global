import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, ConfirmDialog, DataTable, EmptyState, IconButton, PageHeader, Switch, toast } from '@/design/components';
import { customFieldApi, KEYS } from '@/features/catalog/api';
import { useCustomFields } from '@/features/catalog/hooks';
import type { CustomFieldDefRow, CustomFieldEntity } from '@/features/catalog/types';
import { useSession } from '@/features/auth/useSession';
import { StopClick } from '@/features/catalog/StopClick';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { CustomFieldDialog } from './CustomFieldDialog';
import { MasterDataTabs } from './MasterDataTabs';

const ENTITIES: CustomFieldEntity[] = ['product', 'variant', 'customer', 'supplier'];

/** FR-203 Custom field builder, one tab per entity. Fields appear on the product editor and can show on the POS card. */
export function CustomFieldsPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const canManage = can(['masterdata.manage']);
  const client = useQueryClient();
  const [entity, setEntity] = useState<CustomFieldEntity>('product');
  const list = useCustomFields(entity);
  const [edit, setEdit] = useState<CustomFieldDefRow | 'new' | null>(null);
  const [del, setDel] = useState<CustomFieldDefRow | null>(null);
  const refresh = () => void client.invalidateQueries({ queryKey: KEYS.customFields });
  const onError = (e: unknown) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') }));
  const rows = list.data ?? [];

  const toggle = useMutation({ mutationFn: (r: CustomFieldDefRow) => customFieldApi.update(r.id, { is_active: !r.is_active }), onSuccess: refresh, onError });
  const reorder = useMutation({ mutationFn: (ids: number[]) => customFieldApi.reorder(ids), onSuccess: refresh, onError });
  const remove = useMutation({
    mutationFn: (r: CustomFieldDefRow) => customFieldApi.remove(r.id),
    onSuccess: () => { refresh(); toast.success(t('masterData.deleted')); setDel(null); },
    onError: (e) => { setDel(null); onError(e); },
  });
  const move = (r: CustomFieldDefRow, dir: -1 | 1) => {
    const ids = rows.map((x) => x.id);
    const i = ids.indexOf(r.id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j]!, ids[i]!];
    reorder.mutate(ids);
  };

  const columns = useMemo<ColumnDef<CustomFieldDefRow, unknown>[]>(() => {
    const flag = (v: boolean) => (v ? <Badge tone="navy">{t('common.yes')}</Badge> : <span className="text-ink-subtle">—</span>);
    const cols: ColumnDef<CustomFieldDefRow, unknown>[] = [
      { id: 'label', header: t('masterData.fields.nameEn'), cell: ({ row }) => <div><div className="font-medium">{row.original.label_en}</div><code className="text-xs text-ink-subtle">{row.original.key}</code></div> },
      { id: 'type', header: t('masterData.fields.fieldType'), cell: ({ row }) => t(`masterData.fieldTypes.${row.original.field_type}`) },
      { id: 'required', header: t('masterData.fields.isRequired'), cell: ({ row }) => flag(row.original.is_required) },
      { id: 'pos', header: t('masterData.fields.showOnPos'), cell: ({ row }) => flag(row.original.show_on_pos_card) },
      { id: 'status', header: t('common.status'), cell: ({ row }) => canManage ? <StopClick><Switch label={<span className="sr-only">{t('common.active')}</span>} checked={row.original.is_active} onChange={() => toggle.mutate(row.original)} /></StopClick> : <Badge tone={row.original.is_active ? 'success' : 'neutral'}>{row.original.is_active ? t('common.active') : t('common.inactive')}</Badge> },
    ];
    if (canManage) {
      cols.push({
        id: 'actions',
        header: () => <span className="sr-only">{t('common.actions')}</span>,
        cell: ({ row }) => {
          const i = rows.findIndex((x) => x.id === row.original.id);
          return (
            <StopClick className="flex justify-end gap-1">
              <IconButton label={t('masterData.moveUp')} disabled={i <= 0 || reorder.isPending} onClick={() => move(row.original, -1)}><ArrowUp className="h-4 w-4" aria-hidden="true" /></IconButton>
              <IconButton label={t('masterData.moveDown')} disabled={i < 0 || i >= rows.length - 1 || reorder.isPending} onClick={() => move(row.original, 1)}><ArrowDown className="h-4 w-4" aria-hidden="true" /></IconButton>
              <IconButton label={t('common.edit')} onClick={() => setEdit(row.original)}><Pencil className="h-4 w-4" aria-hidden="true" /></IconButton>
              <IconButton label={t('common.delete')} onClick={() => setDel(row.original)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>
            </StopClick>
          );
        },
      });
    }
    return cols;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [t, canManage, rows, reorder.isPending]);

  const addButton = canManage ? <Button size="sm" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setEdit('new')}>{t('masterData.addField')}</Button> : null;
  const err = list.error ? { message: list.error instanceof Error ? list.error.message : 'errors.unknown', requestId: list.error instanceof ApiError ? list.error.requestId : undefined } : null;

  return (
    <>
      <PageHeader title={t('settings.masterData')} description={t('masterData.customFieldsSubtitle')} crumbs={[{ label: t('settings.title') }, { label: t('settings.masterData') }]} />
      <MasterDataTabs />
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" aria-label={t('masterData.entity')} className="flex gap-1">
          {ENTITIES.map((e) => (
            <button key={e} type="button" role="tab" aria-selected={entity === e} onClick={() => setEntity(e)} className={cn('rounded-pill px-3 py-1 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light', entity === e ? 'bg-navy-100 text-navy-900' : 'text-ink-muted hover:bg-navy-50')}>
              {t(`masterData.entities.${e}`)}
            </button>
          ))}
        </div>
        {addButton}
      </div>
      <DataTable columns={columns} data={rows} getRowId={(r) => String(r.id)} loading={list.isLoading} error={err} onRetry={() => void list.refetch()} onRowClick={canManage ? setEdit : undefined} compact caption={t(`masterData.entities.${entity}`)} empty={<EmptyState body={t('masterData.emptyFields')} action={addButton} />} />
      <CustomFieldDialog entity={entity} row={edit} onClose={() => setEdit(null)} />
      <ConfirmDialog open={del !== null} onOpenChange={(o) => !o && setDel(null)} danger title={t('masterData.deleteTitle')} body={t('masterData.deleteBody', { name: del?.label_en ?? '' })} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (del) remove.mutate(del); }} />
    </>
  );
}
