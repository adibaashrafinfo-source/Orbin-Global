import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Checkbox, DataTable, EmptyState, IconButton, Input, Select, toast } from '@/design/components';
import { KEYS, variantApi } from '../api';
import { useMaster } from '../hooks';
import { str } from '../util';
import type { Fitment } from '../types';
import { serverFieldError, useApiError } from '../useApiError';
import type { StepProps } from './steps';

const yearLabel = (f: Fitment) => (f.year_to ? `${f.year_from}–${f.year_to}` : `${f.year_from}+`);

/** Step 4. Which vehicles each variant fits (FR-306): make → model → years, applied to one variant or all of them, or copied across. */
export function FitmentStep({ product, canManage }: StepProps) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const variants = product.variants ?? [];
  const makes = useMaster('vehicle-makes');
  const models = useMaster('vehicle-models');
  const [variantId, setVariantId] = useState<number | null>(variants[0]?.id ?? null);
  const current = variants.find((v) => v.id === variantId) ?? variants[0] ?? null;
  const [makeId, setMakeId] = useState('');
  const [modelId, setModelId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [trim, setTrim] = useState('');
  const [all, setAll] = useState(false);
  const [targets, setTargets] = useState<number[]>([]);
  const refresh = () => void client.invalidateQueries({ queryKey: KEYS.product(product.id) });

  const add = useMutation({
    mutationFn: () => variantApi.addFitment(current!.id, { vehicle_model_id: Number(modelId), year_from: Number(from), year_to: to ? Number(to) : null, trim: trim.trim() || null, apply_to_all_variants: all }),
    onSuccess: (r) => { refresh(); setTrim(''); toast.success(t('products.fitment.added', { count: r.created, skipped: r.skipped })); },
    onError,
  });
  const remove = useMutation({ mutationFn: (id: number) => variantApi.removeFitment(id), onSuccess: refresh, onError });
  const copy = useMutation({
    mutationFn: () => variantApi.copyFitments(current!.id, targets),
    onSuccess: (r) => { refresh(); setTargets([]); toast.success(t('products.fitment.added', { count: r.created, skipped: r.skipped })); },
    onError,
  });

  const columns = useMemo<ColumnDef<Fitment, unknown>[]>(() => {
    const cols: ColumnDef<Fitment, unknown>[] = [
      { id: 'make', header: t('masterData.fields.make'), cell: ({ row }) => row.original.make ?? '—' },
      { id: 'model', header: t('products.fields.fitsModel'), cell: ({ row }) => `${row.original.model ?? ''}${row.original.generation_code ? ` (${row.original.generation_code})` : ''}` },
      { id: 'years', header: t('products.fitment.years'), cell: ({ row }) => <span className="tabular">{yearLabel(row.original)}</span> },
      { id: 'trim', header: t('products.fitment.trim'), cell: ({ row }) => row.original.trim ?? '—' },
    ];
    if (canManage) cols.push({ id: 'actions', header: () => <span className="sr-only">{t('common.actions')}</span>, cell: ({ row }) => <div className="flex justify-end"><IconButton label={t('common.delete')} onClick={() => remove.mutate(row.original.id)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton></div> });
    return cols;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [t, canManage]);

  if (!current) return <EmptyState body={t('products.fitment.needVariant')} />;
  const modelOptions = (models.data ?? []).filter((m) => m.is_active && String(m.make_id) === makeId).map((m) => ({ value: String(m.id), label: `${String(m.name)}${m.generation_code ? ` (${str(m.generation_code)})` : ''}` }));
  const others = variants.filter((v) => v.id !== current.id);

  return (
    <section aria-labelledby="fit-h" className="grid gap-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h2 id="fit-h" className="font-heading text-lg font-bold text-navy-900">{t('products.steps.fitment')}</h2>
        <div className="w-72"><Select label={t('products.fitment.variant')} value={String(current.id)} onChange={(v) => setVariantId(Number(v))} options={variants.map((v) => ({ value: String(v.id), label: `${v.sku}${v.variant_name ? ` · ${v.variant_name}` : ''}` }))} /></div>
      </div>

      {canManage && (
        <div className="grid gap-3 rounded-card border border-navy-100 bg-surface p-4 md:grid-cols-6">
          <div className="md:col-span-2"><Select label={t('masterData.fields.make')} value={makeId || undefined} onChange={(v) => { setMakeId(v); setModelId(''); }} options={(makes.data ?? []).filter((m) => m.is_active).map((m) => ({ value: String(m.id), label: String(m.name) }))} /></div>
          <div className="md:col-span-2"><Select label={t('products.fields.fitsModel')} value={modelId || undefined} disabled={!makeId} onChange={setModelId} options={modelOptions} error={serverFieldError(t, add.error, 'vehicle_model_id')} /></div>
          <Input label={t('products.fitment.yearFrom')} type="number" value={from} onChange={(e) => setFrom(e.target.value)} error={serverFieldError(t, add.error, 'year_from')} />
          <Input label={t('products.fitment.yearTo')} type="number" value={to} onChange={(e) => setTo(e.target.value)} error={serverFieldError(t, add.error, 'year_to')} />
          <div className="md:col-span-3"><Input label={t('products.fitment.trim')} value={trim} onChange={(e) => setTrim(e.target.value)} /></div>
          <div className="flex items-end md:col-span-2">{variants.length > 1 && <Checkbox checked={all} onChange={setAll} label={t('products.fitment.applyAll')} />}</div>
          <div className="flex items-end justify-end"><Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} disabled={!modelId || !from} loading={add.isPending} onClick={() => add.mutate()}>{t('common.add')}</Button></div>
        </div>
      )}

      <DataTable columns={columns} data={current.fitments ?? []} getRowId={(f) => String(f.id)} compact caption={t('products.steps.fitment')} empty={<EmptyState body={t('products.fitment.empty')} />} />

      {canManage && others.length > 0 && (current.fitments ?? []).length > 0 && (
        <details className="rounded-card border border-navy-100 p-4">
          <summary className="cursor-pointer text-sm font-semibold text-navy-900">{t('products.fitment.copyTitle')}</summary>
          <div className="mt-3 grid gap-2 sm:grid-cols-2">{others.map((v) => <Checkbox key={v.id} checked={targets.includes(v.id)} onChange={(c) => setTargets(c ? [...targets, v.id] : targets.filter((x) => x !== v.id))} label={v.sku} />)}</div>
          <Button className="mt-3" variant="secondary" size="sm" disabled={targets.length === 0} loading={copy.isPending} onClick={() => copy.mutate()}>{t('products.fitment.copy')}</Button>
        </details>
      )}
    </section>
  );
}
