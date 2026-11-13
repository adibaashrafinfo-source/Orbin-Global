import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, Button, IconButton, Input, Select } from '@/design/components';
import { KEYS, variantApi } from '../api';
import type { Variant } from '../types';
import { serverFieldError, useApiError } from '../useApiError';

const REF_TYPES = ['oem', 'aftermarket', 'competitor', 'supplier'] as const;

/** Cross-references (OEM / aftermarket numbers) and bundle components of one saved variant (FR-303, FR-306). */
export function VariantExtras({ productId, variant }: { productId: number; variant: Variant }) {
  return (
    <div className="mt-6 grid gap-6">
      <CrossReferences productId={productId} variant={variant} />
      {variant.is_bundle && <BundleItems productId={productId} variant={variant} />}
    </div>
  );
}

function CrossReferences({ productId, variant }: { productId: number; variant: Variant }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const [number, setNumber] = useState('');
  const [brand, setBrand] = useState('');
  const [type, setType] = useState<string>('oem');
  const refresh = () => void client.invalidateQueries({ queryKey: KEYS.product(productId) });
  const add = useMutation({ mutationFn: () => variantApi.addCrossRef(variant.id, { number: number.trim(), ref_type: type, brand: brand.trim() || null }), onSuccess: () => { setNumber(''); setBrand(''); refresh(); }, onError });
  const remove = useMutation({ mutationFn: (id: number) => variantApi.removeCrossRef(id), onSuccess: refresh, onError });
  return (
    <section aria-labelledby="xref-h">
      <h3 id="xref-h" className="mb-2 border-b border-navy-100 pb-1 font-heading text-sm font-bold text-navy-900">{t('products.crossRefs.title')}</h3>
      <ul className="mb-3 flex flex-wrap gap-2">
        {(variant.cross_references ?? []).map((x) => (
          <li key={x.id} className="flex items-center gap-1 rounded-pill bg-navy-50 py-0.5 ps-3 pe-1 text-sm">
            <Badge tone="navy">{t(`products.crossRefs.types.${x.ref_type}`)}</Badge><code>{x.number}</code>{x.brand && <span className="text-xs text-ink-muted">{x.brand}</span>}
            <IconButton label={t('common.remove')} className="h-7 w-7" onClick={() => remove.mutate(x.id)}><X className="h-3.5 w-3.5" aria-hidden="true" /></IconButton>
          </li>
        ))}
        {(variant.cross_references ?? []).length === 0 && <li className="text-sm text-ink-muted">{t('products.crossRefs.empty')}</li>}
      </ul>
      <div className="grid grid-cols-[8rem_1fr_1fr_auto] items-end gap-2">
        <Select label={t('products.crossRefs.type')} value={type} onChange={setType} options={REF_TYPES.map((r) => ({ value: r, label: t(`products.crossRefs.types.${r}`) }))} />
        <Input label={t('products.crossRefs.number')} value={number} onChange={(e) => setNumber(e.target.value)} error={serverFieldError(t, add.error, 'number')} />
        <Input label={t('products.fields.brand')} value={brand} onChange={(e) => setBrand(e.target.value)} />
        <Button type="button" variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} disabled={number.trim() === ''} loading={add.isPending} onClick={() => add.mutate()}>{t('common.add')}</Button>
      </div>
    </section>
  );
}

function BundleItems({ productId, variant }: { productId: number; variant: Variant }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const [q, setQ] = useState('');
  const [qty, setQty] = useState('1');
  const debounced = useDebouncedValue(q, 200).trim();
  const found = useQuery({ queryKey: ['variant-search', debounced], queryFn: () => variantApi.search(debounced, 8), enabled: debounced.length >= 2 });
  const refresh = () => void client.invalidateQueries({ queryKey: KEYS.product(productId) });
  const add = useMutation({ mutationFn: (componentId: number) => variantApi.addBundleItem(variant.id, componentId, qty), onSuccess: () => { setQ(''); setQty('1'); refresh(); }, onError });
  const remove = useMutation({ mutationFn: (id: number) => variantApi.removeBundleItem(id), onSuccess: refresh, onError });
  return (
    <section aria-labelledby="bundle-h">
      <h3 id="bundle-h" className="mb-1 border-b border-navy-100 pb-1 font-heading text-sm font-bold text-navy-900">{t('products.bundle.title')}</h3>
      <p className="mb-2 text-xs text-ink-muted">{t('products.bundle.hint')}</p>
      <ul className="mb-3 grid gap-1">
        {(variant.bundle_items ?? []).map((b) => (
          <li key={b.id} className="flex items-center justify-between rounded-input bg-navy-50 px-3 py-1.5 text-sm">
            <span><code>{b.sku}</code> <span className="text-ink-muted">{b.variant_name}</span> <Badge tone="navy">× {Number(b.qty)}</Badge></span>
            <IconButton label={t('common.remove')} className="h-7 w-7" onClick={() => remove.mutate(b.id)}><X className="h-3.5 w-3.5" aria-hidden="true" /></IconButton>
          </li>
        ))}
      </ul>
      <div className="grid grid-cols-[1fr_6rem] gap-2">
        <Input label={t('products.bundle.find')} type="search" value={q} onChange={(e) => setQ(e.target.value)} />
        <Input label={t('products.bundle.qty')} type="number" min={1} value={qty} onChange={(e) => setQty(e.target.value)} />
      </div>
      {debounced.length >= 2 && (
        <ul className="mt-2 grid gap-1" aria-label={t('products.bundle.results')}>
          {(found.data ?? []).filter((c) => c.id !== variant.id).map((c) => (
            <li key={c.id}><Button type="button" variant="ghost" size="sm" className="w-full justify-between" loading={add.isPending && add.variables === c.id} onClick={() => add.mutate(c.id)}><span><code>{c.sku}</code> {c.variant_name}</span><Plus className="h-4 w-4" aria-hidden="true" /></Button></li>
          ))}
          {found.data?.length === 0 && <li className="px-2 text-sm text-ink-muted">{t('common.noResults')}</li>}
        </ul>
      )}
    </section>
  );
}
