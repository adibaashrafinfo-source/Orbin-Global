import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Checkbox, IconButton, Input, Modal, Select, toast } from '@/design/components';
import { KEYS, variantApi, type MatrixPlanRow, type MatrixSpec } from '../api';
import { useLookupOptions } from '../hooks';
import { ADJUSTMENTS, FEATURE_FLAGS, POSITIONS, type FeatureFlag } from '../types';
import { serverFieldError, useApiError } from '../useApiError';
import { useDerivedState } from '../util';
import { NONE } from './VariantForm';

interface SizeRow { width: string; height: string; thickness: string; label: string }
interface FeatureSet { adjustment: string; flags: FeatureFlag[] }
interface State {
  positions: string[]; sizes: SizeRow[]; finishes: string[]; sets: FeatureSet[];
  retail: string; wholesale: string; dealer: string; mrp: string; min: string; glass: string; tint: string;
}
const initial = (): State => ({ positions: ['left', 'right'], sizes: [], finishes: [], sets: [{ adjustment: NONE, flags: [] }], retail: '', wholesale: '', dealer: '', mrp: '', min: '', glass: NONE, tint: NONE });
const n = (v: string): number | null => (v.trim() === '' ? null : Number(v));

function toSpec(s: State, canPrice: boolean): MatrixSpec {
  const defaults: Record<string, unknown> = { retail_price: s.retail.trim() };
  if (canPrice) for (const [k, v] of [['wholesale_price', s.wholesale], ['dealer_price', s.dealer], ['mrp', s.mrp], ['min_price', s.min]] as const) if (v.trim() !== '') defaults[k] = v.trim();
  if (s.glass !== NONE) defaults.glass_type_id = Number(s.glass);
  if (s.tint !== NONE) defaults.tint_id = Number(s.tint);
  return {
    positions: s.positions,
    sizes: s.sizes.map((r) => ({ width_mm: n(r.width), height_mm: n(r.height), thickness_mm: n(r.thickness), label: r.label.trim() || undefined })),
    color_finish_ids: s.finishes.map(Number),
    feature_sets: s.sets.map((f) => ({ ...(f.adjustment !== NONE ? { adjustment: f.adjustment } : {}), ...Object.fromEntries(f.flags.map((k) => [k, true])) })),
    defaults,
  };
}

const toggle = <T,>(list: T[], v: T): T[] => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

/** FR-304 Variant matrix: pick positions, sizes, finishes and feature sets; preview the SKUs; create them in one go. Existing combinations are skipped. */
export function MatrixDialog({ productId, open, canPrice, onClose }: { productId: number; open: boolean; canPrice: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const glass = useLookupOptions('glass_type');
  const tints = useLookupOptions('glass_tint');
  const finishes = useLookupOptions('color_finish');
  const [s, setS] = useDerivedState<State>(open, initial);
  const [plan, setPlan] = useState<MatrixPlanRow[] | null>(null);
  const patch = (p: Partial<State>) => { setPlan(null); setS((x) => ({ ...x, ...p })); };

  const preview = useMutation({ mutationFn: () => variantApi.matrix(productId, toSpec(s, canPrice), true), onSuccess: (r) => setPlan(r.plan), onError });
  const create = useMutation({
    mutationFn: () => variantApi.matrix(productId, toSpec(s, canPrice), false),
    onSuccess: (r) => { void client.invalidateQueries({ queryKey: KEYS.product(productId) }); toast.success(t('products.matrix.created', { count: r.created.length, skipped: r.skipped })); setPlan(null); onClose(); },
    onError,
  });
  const fresh = (plan ?? []).filter((r) => !r.duplicate).length;
  const valid = s.positions.length > 0 && s.retail.trim() !== '';
  const opt = (rows: { value: string; label: string }[]) => [{ value: NONE, label: t('common.none') }, ...rows];
  const setSize = (i: number, p: Partial<SizeRow>) => patch({ sizes: s.sizes.map((r, k) => (k === i ? { ...r, ...p } : r)) });
  const setSet = (i: number, p: Partial<FeatureSet>) => patch({ sets: s.sets.map((r, k) => (k === i ? { ...r, ...p } : r)) });
  const fieldErr = (k: string) => serverFieldError(t, preview.error ?? create.error, k);

  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-3xl" title={t('products.matrix.title')} description={t('products.matrix.subtitle')}
      footer={<>
        <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="secondary" disabled={!valid} loading={preview.isPending} onClick={() => preview.mutate()}>{t('products.matrix.preview')}</Button>
        <Button disabled={!plan || fresh === 0} loading={create.isPending} onClick={() => create.mutate()}>{t('products.matrix.create', { count: fresh })}</Button>
      </>}>
      <div className="grid gap-5">
        <fieldset className="grid gap-2"><legend className="mb-1 text-sm font-medium">{t('products.fields.position')}</legend>
          <div className="flex flex-wrap gap-4">{POSITIONS.map((p) => <Checkbox key={p} checked={s.positions.includes(p)} onChange={() => patch({ positions: toggle(s.positions, p) })} label={t(`products.positions.${p}`)} />)}</div>
        </fieldset>

        <fieldset className="grid gap-2"><legend className="mb-1 text-sm font-medium">{t('products.matrix.sizes')} <span className="font-normal text-ink-muted">({t('common.optional')})</span></legend>
          {s.sizes.map((r, i) => (
            <div key={i} className="grid grid-cols-[1fr_1fr_1fr_1.5fr_auto] items-end gap-2">
              <Input aria-label={t('products.fields.widthMm')} placeholder={t('products.fields.widthMm')} type="number" value={r.width} onChange={(e) => setSize(i, { width: e.target.value })} />
              <Input aria-label={t('products.fields.heightMm')} placeholder={t('products.fields.heightMm')} type="number" value={r.height} onChange={(e) => setSize(i, { height: e.target.value })} />
              <Input aria-label={t('products.fields.thicknessMm')} placeholder={t('products.fields.thicknessMm')} type="number" value={r.thickness} onChange={(e) => setSize(i, { thickness: e.target.value })} />
              <Input aria-label={t('products.fields.sizeLabel')} placeholder={t('products.fields.sizeLabel')} value={r.label} onChange={(e) => setSize(i, { label: e.target.value })} />
              <IconButton label={t('common.remove')} onClick={() => patch({ sizes: s.sizes.filter((_, k) => k !== i) })}><X className="h-4 w-4" aria-hidden="true" /></IconButton>
            </div>
          ))}
          <div><Button type="button" size="sm" variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => patch({ sizes: [...s.sizes, { width: '', height: '', thickness: '', label: '' }] })}>{t('products.matrix.addSize')}</Button></div>
        </fieldset>

        {finishes.length > 0 && <fieldset className="grid gap-2"><legend className="mb-1 text-sm font-medium">{t('products.fields.colorFinish')} <span className="font-normal text-ink-muted">({t('common.optional')})</span></legend>
          <div className="flex flex-wrap gap-4">{finishes.map((f) => <Checkbox key={f.value} checked={s.finishes.includes(f.value)} onChange={() => patch({ finishes: toggle(s.finishes, f.value) })} label={f.label} />)}</div>
        </fieldset>}

        <fieldset className="grid gap-3"><legend className="mb-1 text-sm font-medium">{t('products.matrix.featureSets')}</legend>
          {s.sets.map((fs, i) => (
            <div key={i} className="rounded-card border border-navy-100 p-3">
              <div className="mb-2 flex items-end gap-2">
                <div className="w-48"><Select label={t('products.fields.adjustment')} value={fs.adjustment} onChange={(v) => setSet(i, { adjustment: v })} options={opt(ADJUSTMENTS.map((a) => ({ value: a, label: t(`products.adjustments.${a}`) })))} /></div>
                {s.sets.length > 1 && <IconButton label={t('common.remove')} onClick={() => patch({ sets: s.sets.filter((_, k) => k !== i) })}><X className="h-4 w-4" aria-hidden="true" /></IconButton>}
              </div>
              <div className="flex flex-wrap gap-x-4 gap-y-2">{FEATURE_FLAGS.map((k) => <Checkbox key={k} checked={fs.flags.includes(k)} onChange={() => setSet(i, { flags: toggle(fs.flags, k) })} label={t(`products.features.${k}`)} />)}</div>
            </div>
          ))}
          <div><Button type="button" size="sm" variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => patch({ sets: [...s.sets, { adjustment: NONE, flags: [] }] })}>{t('products.matrix.addSet')}</Button></div>
        </fieldset>

        <div className="grid gap-3 sm:grid-cols-3">
          <Input label={t('products.fields.retail_price')} required inputMode="decimal" value={s.retail} onChange={(e) => patch({ retail: e.target.value })} error={fieldErr('defaults.retail_price')} />
          {canPrice && <><Input label={t('products.fields.wholesale_price')} inputMode="decimal" value={s.wholesale} onChange={(e) => patch({ wholesale: e.target.value })} />
            <Input label={t('products.fields.dealer_price')} inputMode="decimal" value={s.dealer} onChange={(e) => patch({ dealer: e.target.value })} />
            <Input label={t('products.fields.mrp')} inputMode="decimal" value={s.mrp} onChange={(e) => patch({ mrp: e.target.value })} />
            <Input label={t('products.fields.min_price')} inputMode="decimal" value={s.min} onChange={(e) => patch({ min: e.target.value })} /></>}
          <Select label={t('products.fields.glassType')} value={s.glass} onChange={(v) => patch({ glass: v })} options={opt(glass)} />
          <Select label={t('products.fields.tint')} value={s.tint} onChange={(v) => patch({ tint: v })} options={opt(tints)} />
        </div>

        {plan && (
          <div className="rounded-card border border-navy-100" aria-live="polite">
            <p className="border-b border-navy-100 px-3 py-2 text-sm"><Badge tone="navy">{t('products.matrix.willCreate', { count: fresh })}</Badge> <span className="text-ink-muted">{t('products.matrix.willSkip', { count: plan.length - fresh })}</span></p>
            <ul className="max-h-56 overflow-y-auto text-sm">
              {plan.map((r) => <li key={r.sku + r.variant_name} className="flex items-center justify-between gap-2 px-3 py-1.5 odd:bg-navy-50/50"><code>{r.sku}</code><span className="truncate text-ink-muted">{r.variant_name}</span>{r.duplicate && <Badge tone="warning">{t('products.matrix.exists')}</Badge>}</li>)}
            </ul>
          </div>
        )}
      </div>
    </Modal>
  );
}
