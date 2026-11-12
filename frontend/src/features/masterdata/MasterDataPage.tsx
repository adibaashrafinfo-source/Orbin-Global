import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate, useParams } from 'react-router-dom';
import { Badge, Button, ConfirmDialog, DataTable, EmptyState, IconButton, Input, PageHeader, Select, Switch, toast } from '@/design/components';
import { masterApi } from '@/features/catalog/api';
import { lookupLabel, useLookups, useMaster } from '@/features/catalog/hooks';
import type { MasterRow } from '@/features/catalog/types';
import { useSession } from '@/features/auth/useSession';
import { StopClick } from '@/features/catalog/StopClick';
import { str } from '@/features/catalog/util';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { ApiError } from '@/lib/api';
import { isResourceName, RESOURCES, type ColumnDef as Col, type ResourceDef } from './config';
import { MasterDataDialog } from './MasterDataDialog';
import { MasterDataTabs } from './MasterDataTabs';

const ALL = '__all__';

/** Categories are shown parent-first with a depth so the table reads as a tree. */
function treeOrder(rows: MasterRow[]): { row: MasterRow; depth: number }[] {
  const byParent = new Map<number | null, MasterRow[]>();
  for (const r of rows) {
    const p = (r.parent_id as number | null) ?? null;
    byParent.set(p, [...(byParent.get(p) ?? []), r]);
  }
  const out: { row: MasterRow; depth: number }[] = [];
  const walk = (parent: number | null, depth: number) => {
    for (const r of byParent.get(parent) ?? []) {
      out.push({ row: r, depth });
      walk(r.id, depth + 1);
    }
  };
  walk(null, 0);
  // Rows whose parent was filtered out still appear, at the top level.
  const seen = new Set(out.map((o) => o.row.id));
  for (const r of rows) if (!seen.has(r.id)) out.push({ row: r, depth: 0 });
  return out;
}

export function MasterDataPage() {
  const { resource } = useParams();
  if (!isResourceName(resource)) return <Navigate to="/settings/master/vehicle-makes" replace />;
  return <MasterList key={resource} def={RESOURCES[resource]} />;
}

function MasterList({ def }: { def: ResourceDef }) {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const client = useQueryClient();
  const canManage = can(['masterdata.manage']);
  const list = useMaster(def.name);
  const makes = useMaster('vehicle-makes');
  const lookups = useLookups();
  const [search, setSearch] = useState('');
  const q = useDebouncedValue(search, 200).trim().toLowerCase();
  const [makeFilter, setMakeFilter] = useState(ALL);
  const [edit, setEdit] = useState<MasterRow | 'new' | null>(null);
  const [del, setDel] = useState<MasterRow | null>(null);
  const lang = i18n.language;

  const invalidate = () => void client.invalidateQueries({ queryKey: ['master'] });
  const onError = (e: unknown) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') }));
  const toggle = useMutation({ mutationFn: (r: MasterRow) => masterApi.update(def.name, r.id, { is_active: !r.is_active }), onSuccess: invalidate, onError });
  const remove = useMutation({
    mutationFn: (r: MasterRow) => masterApi.remove(def.name, r.id),
    onSuccess: () => {
      invalidate();
      toast.success(t('masterData.deleted'));
      setDel(null);
    },
    onError: (e) => {
      setDel(null);
      onError(e);
    },
  });
  const reorder = useMutation({ mutationFn: (ids: number[]) => masterApi.reorder(def.name, ids), onSuccess: invalidate, onError });

  const visible = useMemo(() => {
    const rows = (list.data ?? []).filter((r) => {
      if (def.hasMakeFilter && makeFilter !== ALL && String(r.make_id) !== makeFilter) return false;
      if (!q) return true;
      return Object.values(r).some((v) => typeof v === 'string' && v.toLowerCase().includes(q));
    });
    return def.tree && !q ? treeOrder(rows) : rows.map((row) => ({ row, depth: 0 }));
  }, [list.data, def, makeFilter, q]);

  const move = (r: MasterRow, dir: -1 | 1) => {
    const siblings = (list.data ?? []).filter((x) => (x.parent_id ?? null) === (r.parent_id ?? null));
    const i = siblings.findIndex((x) => x.id === r.id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= siblings.length) return;
    const ids = siblings.map((x) => x.id);
    [ids[i], ids[j]] = [ids[j]!, ids[i]!];
    reorder.mutate(ids);
  };
  const siblingIndex = (r: MasterRow) => {
    const s = (list.data ?? []).filter((x) => (x.parent_id ?? null) === (r.parent_id ?? null));
    return { i: s.findIndex((x) => x.id === r.id), n: s.length };
  };

  const cell = (c: Col, r: MasterRow, depth: number) => {
    const v = r[c.key];
    switch (c.kind) {
      case 'bool': return v ? t('common.yes') : t('common.no');
      case 'count': return str(v, '0');
      case 'percent': return `${Number(v).toFixed(2)}%`;
      case 'make': return str(makes.data?.find((m) => m.id === v)?.name, '—');
      case 'lookup': return lookupLabel(lookups.data, c.lookup ?? '', v as number | null, lang) || '—';
      case 'number': return str(v, '—');
      default: {
        const text = (c.key === 'name_en' && lang === 'bn' ? str(r.name_bn) : '') || str(v, '—');
        return <span style={{ paddingInlineStart: c.key === def.titleKey && def.tree ? depth * 20 : 0 }}>{c.key === def.titleKey && depth > 0 ? '└ ' : ''}{text}</span>;
      }
    }
  };

  const depthOf = useMemo(() => new Map(visible.map((v) => [v.row.id, v.depth])), [visible]);
  const columns = useMemo<ColumnDef<MasterRow, unknown>[]>(() => {
    const cols: ColumnDef<MasterRow, unknown>[] = def.columns.map((c) => ({ id: c.key, header: t(`masterData.fields.${c.label}`), cell: ({ row }) => cell(c, row.original, depthOf.get(row.original.id) ?? 0) }));
    cols.push({
      id: 'status',
      header: t('common.status'),
      cell: ({ row }) => (canManage ? (
        <StopClick>
          <Switch label={<span className="sr-only">{t('common.active')}</span>} checked={row.original.is_active} onChange={() => toggle.mutate(row.original)} />
        </StopClick>
      ) : <Badge tone={row.original.is_active ? 'success' : 'neutral'}>{row.original.is_active ? t('common.active') : t('common.inactive')}</Badge>),
    });
    if (canManage) {
      cols.push({
        id: 'actions',
        header: () => <span className="sr-only">{t('common.actions')}</span>,
        cell: ({ row }) => {
          const r = row.original;
          const { i, n } = siblingIndex(r);
          return (
            <StopClick className="flex justify-end gap-1">
              {def.reorder && <IconButton label={t('masterData.moveUp')} disabled={i <= 0 || reorder.isPending} onClick={() => move(r, -1)}><ArrowUp className="h-4 w-4" aria-hidden="true" /></IconButton>}
              {def.reorder && <IconButton label={t('masterData.moveDown')} disabled={i < 0 || i >= n - 1 || reorder.isPending} onClick={() => move(r, 1)}><ArrowDown className="h-4 w-4" aria-hidden="true" /></IconButton>}
              <IconButton label={t('common.edit')} onClick={() => setEdit(r)}><Pencil className="h-4 w-4" aria-hidden="true" /></IconButton>
              <IconButton label={t('common.delete')} onClick={() => setDel(r)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>
            </StopClick>
          );
        },
      });
    }
    return cols;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [t, lang, def, canManage, makes.data, lookups.data, list.data, depthOf, reorder.isPending]);

  const err = list.error ? { message: list.error instanceof Error ? list.error.message : 'errors.unknown', requestId: list.error instanceof ApiError ? list.error.requestId : undefined } : null;
  const addButton = canManage ? <Button size="sm" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setEdit('new')}>{t('masterData.addTitle', { item: t(`masterData.singular.${def.name}`) })}</Button> : null;

  return (
    <>
      <PageHeader title={t('settings.masterData')} description={t('masterData.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('settings.masterData') }]} />
      <MasterDataTabs />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-64"><Input label={t('common.search')} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('masterData.searchPlaceholder')} /></div>
        {def.hasMakeFilter && (
          <div className="w-56">
            <Select label={t('masterData.fields.make')} value={makeFilter} onChange={setMakeFilter} options={[{ value: ALL, label: t('common.all') }, ...(makes.data ?? []).map((m) => ({ value: String(m.id), label: String(m.name) }))]} />
          </div>
        )}
        <div className="ms-auto">{addButton}</div>
      </div>
      <DataTable
        columns={columns}
        data={visible.map((v) => v.row)}
        getRowId={(r) => String(r.id)}
        loading={list.isLoading}
        error={err}
        onRetry={() => void list.refetch()}
        onRowClick={canManage ? setEdit : undefined}
        compact
        caption={t(`masterData.plural.${def.name}`)}
        empty={<EmptyState body={t('masterData.empty')} action={addButton} />}
      />
      <MasterDataDialog resource={def.name} row={edit} presetMake={makeFilter === ALL ? '' : makeFilter} onClose={() => setEdit(null)} />
      <ConfirmDialog
        open={del !== null}
        onOpenChange={(o) => !o && setDel(null)}
        danger
        title={t('masterData.deleteTitle')}
        body={t('masterData.deleteBody', { name: del ? str(del[def.titleKey]) : '' })}
        confirmLabel={t('common.delete')}
        loading={remove.isPending}
        onConfirm={() => { if (del) remove.mutate(del); }}
      />
    </>
  );
}
