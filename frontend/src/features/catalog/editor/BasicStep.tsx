import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useUnsavedChangesGuard } from '@/app/useUnsavedChangesGuard';
import { Button, Card, CardBody, Input, Select, Textarea, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { KEYS, productApi } from '../api';
import { useLookupOptions, useMaster, useMasterOptions } from '../hooks';
import { MIRROR_TYPES, type MirrorType, type Product } from '../types';
import { serverFieldError, useApiError } from '../useApiError';
import { useDerivedState } from '../util';
import type { StepProps } from './steps';

interface Form {
  code: string; name_en: string; name_bn: string; mirror_type: MirrorType | ''; category_id: string; brand_id: string;
  quality_grade_id: string; origin_country_id: string; unit_id: string; tax_rate_id: string;
  short_description: string; description: string; warranty_months: string; return_window_days: string; tags: string;
}
const NONE = '__none__';
const AUTOSAVE_MS = 1200;

const fromProduct = (p: Product | null): Form => ({
  code: p?.code ?? '', name_en: p?.name_en ?? '', name_bn: p?.name_bn ?? '', mirror_type: p?.mirror_type ?? '',
  category_id: p ? String(p.category_id) : '', brand_id: p?.brand_id ? String(p.brand_id) : NONE,
  quality_grade_id: p ? String(p.quality_grade_id) : '', origin_country_id: p?.origin_country_id ? String(p.origin_country_id) : NONE,
  unit_id: p ? String(p.unit_id) : '', tax_rate_id: p?.tax_rate_id ? String(p.tax_rate_id) : NONE,
  short_description: p?.short_description ?? '', description: p?.description ?? '', warranty_months: String(p?.warranty_months ?? 0),
  return_window_days: p?.return_window_days == null ? '' : String(p.return_window_days), tags: (p?.tags ?? []).join(', '),
});

const id = (v: string): number | null => (v === '' || v === NONE ? null : Number(v));
const body = (f: Form): Partial<Product> => ({
  code: f.code.trim().toUpperCase(), name_en: f.name_en.trim(), name_bn: f.name_bn.trim() || null, mirror_type: f.mirror_type || undefined,
  category_id: id(f.category_id) as number, brand_id: id(f.brand_id), quality_grade_id: id(f.quality_grade_id) as number, origin_country_id: id(f.origin_country_id),
  unit_id: id(f.unit_id) as number, tax_rate_id: id(f.tax_rate_id), short_description: f.short_description.trim() || null, description: f.description.trim() || null,
  warranty_months: Number(f.warranty_months || 0), return_window_days: f.return_window_days === '' ? null : Number(f.return_window_days),
  tags: f.tags.split(',').map((x) => x.trim()).filter(Boolean),
});
const complete = (f: Form) => f.code.trim() !== '' && f.name_en.trim() !== '' && f.mirror_type !== '' && f.category_id !== '' && f.quality_grade_id !== '' && f.unit_id !== '';

/** Step 1. A new product is created as a draft; an existing draft autosaves while you type, a published product saves on demand. */
export function BasicStep({ product, canManage }: Omit<StepProps, 'product'> & { product: Product | null }) {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const client = useQueryClient();
  const onError = useApiError();
  const units = useMaster('units');
  const taxes = useMaster('tax-rates');
  const categories = useMasterOptions('categories', 'name_en', 'name_bn');
  const brands = useMasterOptions('product-brands', 'name');
  const grades = useLookupOptions('quality_grade');
  const countries = useLookupOptions('origin_country');
  const [form, setForm] = useDerivedState<Form>(product?.id ?? 'new', () => {
    const f = fromProduct(product);
    if (!product) {
      f.unit_id = String((units.data ?? []).find((u) => u.is_active && u.is_pair_unit)?.id ?? (units.data ?? [])[0]?.id ?? '');
      f.tax_rate_id = String((taxes.data ?? []).find((x) => x.is_default)?.id ?? NONE);
    }
    return f;
  });
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((s) => ({ ...s, [k]: v }));

  const baseline = JSON.stringify(body(fromProduct(product)));
  const current = JSON.stringify(body(form));
  const dirty = product ? baseline !== current : JSON.stringify(body(fromProduct(null))) !== current;
  const draft = product !== null && !product.is_active;
  const lastSaved = useRef(baseline);

  const save = useMutation({
    mutationFn: () => (product ? productApi.update(product.id, body(form)) : productApi.create(body(form))),
    onSuccess: (saved) => {
      lastSaved.current = JSON.stringify(body(form));
      client.setQueryData(KEYS.product(saved.id), saved);
      void client.invalidateQueries({ queryKey: KEYS.products, refetchType: 'none' });
      if (!product) {
        toast.success(t('products.draftCreated'));
        navigate(`/products/${saved.id}?step=variants`);
      }
    },
    onError,
  });

  useEffect(() => {
    if (!draft || !canManage || !complete(form) || current === lastSaved.current || save.isPending) return;
    const timer = setTimeout(() => save.mutate(), AUTOSAVE_MS);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [current, draft, canManage]);

  useUnsavedChangesGuard(dirty && !draft && !save.isPending);
  const err = (k: string) => serverFieldError(t, save.error, k);
  const required = (v: string) => (submittedEmpty && v === '' ? t('common.required') : undefined);
  const submittedEmpty = save.isError && save.error instanceof ApiError && save.error.code === 'VALIDATION';
  const lang = i18n.language;
  const opt = (rows: { value: string; label: string }[]) => [{ value: NONE, label: t('common.none') }, ...rows];
  const ro = !canManage;

  return (
    <form noValidate onSubmit={(e) => { e.preventDefault(); if (complete(form)) save.mutate(); }} className="grid gap-5">
      <Card><CardBody className="grid gap-4 md:grid-cols-2">
        <Input label={t('products.fields.name')} required disabled={ro} value={form.name_en} onChange={(e) => set('name_en', e.target.value)} error={err('name_en') ?? required(form.name_en)} />
        <Input label={t('products.fields.nameBn')} disabled={ro} value={form.name_bn} onChange={(e) => set('name_bn', e.target.value)} error={err('name_bn')} />
        <Input label={t('products.fields.code')} required hint={t('products.codeHint')} disabled={ro} value={form.code} onChange={(e) => set('code', e.target.value.toUpperCase())} error={err('code') ?? required(form.code)} />
        <Select label={t('products.fields.mirrorType')} required disabled={ro} value={form.mirror_type || undefined} onChange={(v) => set('mirror_type', v as MirrorType)} error={err('mirror_type')} options={MIRROR_TYPES.map((m) => ({ value: m, label: t(`products.mirrorTypes.${m}`) }))} />
        <Select label={t('products.fields.category')} required disabled={ro} value={form.category_id || undefined} onChange={(v) => set('category_id', v)} error={err('category_id')} options={categories} />
        <Select label={t('products.fields.brand')} disabled={ro} value={form.brand_id} onChange={(v) => set('brand_id', v)} options={opt(brands)} />
        <Select label={t('products.fields.qualityGrade')} required disabled={ro} value={form.quality_grade_id || undefined} onChange={(v) => set('quality_grade_id', v)} error={err('quality_grade_id')} options={grades} />
        <Select label={t('products.fields.origin')} disabled={ro} value={form.origin_country_id} onChange={(v) => set('origin_country_id', v)} options={opt(countries)} />
      </CardBody></Card>
      <Card><CardBody className="grid gap-4 md:grid-cols-2">
        <Select label={t('products.fields.unit')} required disabled={ro} value={form.unit_id || undefined} onChange={(v) => set('unit_id', v)} error={err('unit_id')} options={(units.data ?? []).filter((u) => u.is_active).map((u) => ({ value: String(u.id), label: String((lang === 'bn' && u.name_bn) || u.name_en) }))} />
        <Select label={t('products.fields.tax')} disabled={ro} value={form.tax_rate_id} onChange={(v) => set('tax_rate_id', v)} options={opt((taxes.data ?? []).filter((x) => x.is_active).map((x) => ({ value: String(x.id), label: `${String(x.name)} (${String(x.rate_percent)}%)` })))} />
        <Input label={t('products.fields.warrantyMonths')} type="number" min={0} disabled={ro} value={form.warranty_months} onChange={(e) => set('warranty_months', e.target.value)} error={err('warranty_months')} />
        <Input label={t('products.fields.returnDays')} type="number" min={0} hint={t('products.returnDaysHint')} disabled={ro} value={form.return_window_days} onChange={(e) => set('return_window_days', e.target.value)} error={err('return_window_days')} />
        <div className="md:col-span-2"><Input label={t('products.fields.tags')} hint={t('products.tagsHint')} disabled={ro} value={form.tags} onChange={(e) => set('tags', e.target.value)} /></div>
        <div className="md:col-span-2"><Textarea label={t('products.fields.shortDescription')} rows={2} disabled={ro} value={form.short_description} onChange={(e) => set('short_description', e.target.value)} /></div>
        <div className="md:col-span-2"><Textarea label={t('products.fields.description')} rows={5} disabled={ro} value={form.description} onChange={(e) => set('description', e.target.value)} /></div>
      </CardBody></Card>
      {canManage && (
        <div className="flex items-center justify-end gap-3">
          {draft && <span role="status" className="text-xs text-ink-muted">{save.isPending ? t('common.saving') : dirty ? t('products.unsaved') : t('products.autosaved')}</span>}
          {(!draft || !product) && <Button type="submit" loading={save.isPending} disabled={!dirty}>{product ? t('common.save') : t('products.createDraft')}</Button>}
        </div>
      )}
    </form>
  );
}
