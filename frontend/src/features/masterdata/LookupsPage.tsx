import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, ConfirmDialog, DataTable, EmptyState, ErrorState, IconButton, PageHeader, Skeleton, Switch, toast } from '@/design/components';
import { KEYS, lookupApi } from '@/features/catalog/api';
import { labelOf, useLookups } from '@/features/catalog/hooks';
import type { LookupTypeRow, LookupValueRow } from '@/features/catalog/types';
import { useSession } from '@/features/auth/useSession';
import { StopClick } from '@/features/catalog/StopClick';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { LookupTypeDialog, LookupValueDialog } from './LookupDialogs';
import { MasterDataTabs } from './MasterDataTabs';

/** FR-202 Lookup manager: pick a list on the left, edit its values on the right. */
export function LookupsPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const canManage = can(['masterdata.manage']);
  const client = useQueryClient();
  const lookups = useLookups();
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [typeDialog, setTypeDialog] = useState(false);
  const [valueEdit, setValueEdit] = useState<LookupValueRow | 'new' | null>(null);
  const [delValue, setDelValue] = useState<LookupValueRow | null>(null);
  const [delType, setDelType] = useState<LookupTypeRow | null>(null);
  const lang = i18n.language;

  const types = lookups.data ?? [];
  const current = types.find((x) => x.id === selectedId) ?? types[0] ?? null;
  const refresh = () => void client.invalidateQueries({ queryKey: KEYS.lookups });
  const onError = (e: unknown) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') }));

  const toggle = useMutation({ mutationFn: (v: LookupValueRow) => lookupApi.updateValue(v.id, { is_active: !v.is_active }), onSuccess: refresh, onError });
  const reorder = useMutation({ mutationFn: (ids: number[]) => lookupApi.reorderValues(current!.id, ids), onSuccess: refresh, onError });
  const removeValue = useMutation({
    mutationFn: (v: LookupValueRow) => lookupApi.removeValue(v.id),
    onSuccess: () => { refresh(); toast.success(t('masterData.deleted')); setDelValue(null); },
    onError: (e) => { setDelValue(null); onError(e); },
  });
  const removeType = useMutation({
    mutationFn: (x: LookupTypeRow) => lookupApi.removeType(x.id),
    onSuccess: () => { refresh(); toast.success(t('masterData.deleted')); setDelType(null); setSelectedId(null); },
    onError: (e) => { setDelType(null); onError(e); },
  });

  const move = (v: LookupValueRow, dir: -1 | 1) => {
    if (!current) return;
    const ids = current.values.map((x) => x.id);
    const i = ids.indexOf(v.id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j]!, ids[i]!];
    reorder.mutate(ids);
  };

  const columns = useMemo<ColumnDef<LookupValueRow, unknown>[]>(() => {
    const n = current?.values.length ?? 0;
    const cols: ColumnDef<LookupValueRow, unknown>[] = [
      { id: 'code', header: t('common.code'), cell: ({ row }) => <code className="text-xs">{row.original.code}</code> },
      { id: 'en', header: t('masterData.fields.nameEn'), cell: ({ row }) => row.original.label_en },
      { id: 'bn', header: t('masterData.fields.nameBn'), cell: ({ row }) => row.original.label_bn ?? '—' },
      {
        id: 'status',
        header: t('common.status'),
        cell: ({ row }) => canManage ? (
          <StopClick><Switch label={<span className="sr-only">{t('common.active')}</span>} checked={row.original.is_active} onChange={() => toggle.mutate(row.original)} /></StopClick>
        ) : <Badge tone={row.original.is_active ? 'success' : 'neutral'}>{row.original.is_active ? t('common.active') : t('common.inactive')}</Badge>,
      },
    ];
    if (canManage) {
      cols.push({
        id: 'actions',
        header: () => <span className="sr-only">{t('common.actions')}</span>,
        cell: ({ row }) => {
          const i = current?.values.findIndex((x) => x.id === row.original.id) ?? -1;
          return (
            <StopClick className="flex justify-end gap-1">
              <IconButton label={t('masterData.moveUp')} disabled={i <= 0 || reorder.isPending} onClick={() => move(row.original, -1)}><ArrowUp className="h-4 w-4" aria-hidden="true" /></IconButton>
              <IconButton label={t('masterData.moveDown')} disabled={i < 0 || i >= n - 1 || reorder.isPending} onClick={() => move(row.original, 1)}><ArrowDown className="h-4 w-4" aria-hidden="true" /></IconButton>
              <IconButton label={t('common.edit')} onClick={() => setValueEdit(row.original)}><Pencil className="h-4 w-4" aria-hidden="true" /></IconButton>
              <IconButton label={t('common.delete')} onClick={() => setDelValue(row.original)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>
            </StopClick>
          );
        },
      });
    }
    return cols;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [t, canManage, current, reorder.isPending]);

  return (
    <>
      <PageHeader title={t('settings.masterData')} description={t('masterData.lookupsSubtitle')} crumbs={[{ label: t('settings.title') }, { label: t('settings.masterData') }]} />
      <MasterDataTabs />
      {lookups.isLoading ? <Skeleton className="h-64 w-full" /> : lookups.error ? <ErrorState onRetry={() => void lookups.refetch()} /> : (
        <div className="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
          <div>
            <ul className="flex flex-col gap-0.5" aria-label={t('masterData.lookups')}>
              {types.map((x) => (
                <li key={x.id}>
                  <button type="button" onClick={() => setSelectedId(x.id)} aria-current={current?.id === x.id ? 'true' : undefined} className={cn('flex h-9 w-full items-center justify-between rounded-input px-3 text-start text-sm transition-colors duration-fast focus-visible:focus-ring-light', current?.id === x.id ? 'bg-navy-100 font-semibold text-navy-900' : 'text-ink-muted hover:bg-navy-50')}>
                    <span className="truncate">{labelOf({ label_en: x.name_en, label_bn: x.name_bn }, lang)}</span>
                    <span className="text-xs text-ink-muted">{x.values.length}</span>
                  </button>
                </li>
              ))}
            </ul>
            {canManage && <Button className="mt-3" size="sm" variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setTypeDialog(true)}>{t('masterData.addList')}</Button>}
          </div>
          <section aria-live="polite">
            {current ? (
              <>
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <h2 className="font-heading text-lg font-bold text-navy-900">{labelOf({ label_en: current.name_en, label_bn: current.name_bn }, lang)}</h2>
                    <p className="text-xs text-ink-muted"><code>{current.key}</code>{current.is_system ? ` · ${t('masterData.systemList')}` : ''}</p>
                  </div>
                  {canManage && (
                    <div className="flex gap-2">
                      {!current.is_system && <Button size="sm" variant="secondary" onClick={() => setDelType(current)}>{t('masterData.deleteList')}</Button>}
                      <Button size="sm" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setValueEdit('new')}>{t('masterData.addValue')}</Button>
                    </div>
                  )}
                </div>
                <DataTable columns={columns} data={current.values} getRowId={(r) => String(r.id)} compact caption={current.name_en} onRowClick={canManage ? setValueEdit : undefined} empty={<EmptyState body={t('masterData.emptyValues')} />} />
              </>
            ) : <EmptyState body={t('masterData.empty')} />}
          </section>
        </div>
      )}
      <LookupTypeDialog open={typeDialog} onClose={() => setTypeDialog(false)} onCreated={(row) => setSelectedId(row.id)} />
      <LookupValueDialog type={current} value={valueEdit} onClose={() => setValueEdit(null)} />
      <ConfirmDialog open={delValue !== null} onOpenChange={(o) => !o && setDelValue(null)} danger title={t('masterData.deleteTitle')} body={t('masterData.deleteBody', { name: delValue?.label_en ?? '' })} confirmLabel={t('common.delete')} loading={removeValue.isPending} onConfirm={() => { if (delValue) removeValue.mutate(delValue); }} />
      <ConfirmDialog open={delType !== null} onOpenChange={(o) => !o && setDelType(null)} danger title={t('masterData.deleteListTitle')} body={t('masterData.deleteListBody', { name: delType?.name_en ?? '' })} confirmLabel={t('common.delete')} loading={removeType.isPending} onConfirm={() => { if (delType) removeType.mutate(delType); }} />
    </>
  );
}
