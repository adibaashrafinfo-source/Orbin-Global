import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Button, Drawer, Input, Select, Switch, Textarea, toast } from '@/design/components';
import { KEYS, variantApi } from '../api';
import { useLookupOptions } from '../hooks';
import { FEATURE_FLAGS, type Adjustment, type Position, type Variant } from '../types';
import { serverFieldError, useApiError } from '../useApiError';
import { str, useDerivedState } from '../util';
import { ADJUSTMENT_LIST, blankVariant, fromVariant, NONE, POSITION_LIST, variantBody, type VariantFormState } from './VariantForm';
import { VariantExtras } from './VariantExtras';

interface Props {
  productId: number;
  variant: Variant | 'new' | null;
  canPrice: boolean;
  canCost: boolean;
  onClose: () => void;
}

/** Add or edit one variant (FR-303). Bundle components and cross-references need a saved variant, so they appear after the first save. */
export function VariantDrawer({ productId, variant, canPrice, canCost, onClose }: Props) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const glass = useLookupOptions('glass_type');
  const tints = useLookupOptions('glass_tint');
  const finishes = useLookupOptions('color_finish');
  const isNew = variant === 'new';
  const [f, setF] = useDerivedState<VariantFormState>(variant && variant !== 'new' ? variant.id : variant, () => (variant && variant !== 'new' ? fromVariant(variant) : blankVariant()));
  const set = <K extends keyof VariantFormState>(k: K, v: VariantFormState[K]) => setF((s) => ({ ...s, [k]: v }));

  const save = useMutation({
    mutationFn: () => (variant && variant !== 'new' ? variantApi.update(variant.id, variantBody(f, false, canPrice)) : variantApi.create(productId, variantBody(f, true, canPrice))),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: KEYS.product(productId) });
      toast.success(t('products.variantSaved'));
      onClose();
    },
    onError,
  });
  const barcode = useMutation({
    mutationFn: () => variantApi.barcode((variant as Variant).id),
    onSuccess: (v) => { set('barcode', v.barcode ?? ''); void client.invalidateQueries({ queryKey: KEYS.product(productId) }); },
    onError,
  });
  const err = (k: string) => serverFieldError(t, save.error, k);
  const opt = (rows: { value: string; label: string }[]) => [{ value: NONE, label: t('common.none') }, ...rows];
  const text = (k: keyof VariantFormState, label: string, extra?: { hint?: string; type?: string; money?: boolean }) => (
    <Input label={label} hint={extra?.hint} type={extra?.type} inputMode={extra?.money ? 'decimal' : undefined} value={str(f[k])} onChange={(e) => set(k, e.target.value as never)} error={err(k)} />
  );
  const sw = (k: 'is_glass_only' | 'cover_included' | 'is_discontinued' | 'is_active' | 'is_bundle' | 'track_serial', label: string) => <Switch label={label} checked={f[k]} onChange={(v) => set(k, v)} />;
  const section = (title: string) => <h3 className="mt-2 border-b border-navy-100 pb-1 font-heading text-sm font-bold text-navy-900">{title}</h3>;
  const priceKeys = ['retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price'] as const;

  return (
    <Drawer
      open={variant !== null}
      onOpenChange={(o) => !o && onClose()}
      className="max-w-2xl"
      title={isNew ? t('products.addVariant') : t('products.editVariant')}
      description={!isNew && variant ? variant.sku : undefined}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="variant-form" loading={save.isPending}>{t('common.save')}</Button></>}
    >
      <form id="variant-form" noValidate className="grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
        {section(t('products.variantSections.identity'))}
        <div className="grid gap-3 sm:grid-cols-2">
          {text('sku', t('products.fields.sku'), { hint: t('products.skuHint') })}
          <div className="flex items-end gap-2">
            <div className="flex-1">{text('barcode', t('products.fields.barcode'))}</div>
            {!isNew && <Button type="button" variant="secondary" size="md" loading={barcode.isPending} onClick={() => barcode.mutate()}>{t('products.generate')}</Button>}
          </div>
          {text('aliases', t('products.fields.aliases'), { hint: t('products.aliasesHint') })}
          {text('supplier_part_no', t('products.fields.supplierPart'))}
          {isNew && <div className="sm:col-span-2"><Switch label={t('products.generateBarcode')} checked={f.generate_barcode} onChange={(v) => set('generate_barcode', v)} /></div>}
        </div>

        {section(t('products.variantSections.spec'))}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label={t('products.fields.position')} required value={f.position} onChange={(v) => set('position', v as Position)} error={err('position')} options={POSITION_LIST.map((p) => ({ value: p, label: t(`products.positions.${p}`) }))} />
          <Select label={t('products.fields.adjustment')} value={f.adjustment} onChange={(v) => set('adjustment', v as Adjustment)} options={opt(ADJUSTMENT_LIST.map((a) => ({ value: a, label: t(`products.adjustments.${a}`) })))} />
          {text('size_width_mm', t('products.fields.widthMm'), { type: 'number' })}
          {text('size_height_mm', t('products.fields.heightMm'), { type: 'number' })}
          {text('thickness_mm', t('products.fields.thicknessMm'), { type: 'number' })}
          {text('weight_g', t('products.fields.weightG'), { type: 'number' })}
          <div className="sm:col-span-2">{text('size_label', t('products.fields.sizeLabel'), { hint: t('products.sizeLabelHint') })}</div>
          <Select label={t('products.fields.glassType')} value={f.glass_type_id} onChange={(v) => set('glass_type_id', v)} options={opt(glass)} />
          <Select label={t('products.fields.tint')} value={f.tint_id} onChange={(v) => set('tint_id', v)} options={opt(tints)} />
          <Select label={t('products.fields.colorFinish')} value={f.color_finish_id} onChange={(v) => set('color_finish_id', v)} options={opt(finishes)} />
          {text('pin_count', t('products.fields.pinCount'), { type: 'number' })}
          <div className="sm:col-span-2">{text('connector_note', t('products.fields.connectorNote'))}</div>
          {text('year_from', t('products.fields.yearFrom'), { type: 'number' })}
          {text('year_to', t('products.fields.yearTo'), { type: 'number' })}
        </div>

        {section(t('products.variantSections.features'))}
        <div className="grid gap-2 sm:grid-cols-2">
          {FEATURE_FLAGS.map((k) => <Switch key={k} label={t(`products.features.${k}`)} checked={f.flags[k]} onChange={(v) => setF((s) => ({ ...s, flags: { ...s.flags, [k]: v } }))} />)}
          {sw('is_glass_only', t('products.fields.glassOnly'))}
          {sw('cover_included', t('products.fields.coverIncluded'))}
        </div>

        {section(t('products.variantSections.pricing'))}
        {canPrice ? (
          <div className="grid gap-3 sm:grid-cols-2">
            {priceKeys.map((k) => <div key={k}>{text(k, t(`products.fields.${k}`), { money: true })}</div>)}
            {canCost && !isNew && variant && <Input label={t('products.fields.lastCost')} readOnly disabled value={variant.last_cost ?? ''} hint={t('products.lastCostHint')} />}
          </div>
        ) : <p className="text-sm text-ink-muted">{t('products.pricingLocked')}</p>}

        {section(t('products.variantSections.other'))}
        <div className="grid gap-2 sm:grid-cols-2">{sw('is_active', t('common.active'))}{sw('is_discontinued', t('products.fields.discontinued'))}{sw('track_serial', t('products.fields.trackSerial'))}{sw('is_bundle', t('products.fields.isBundle'))}</div>
        <Textarea label={t('products.fields.internalNote')} rows={2} value={f.internal_note} onChange={(e) => set('internal_note', e.target.value)} />
      </form>
      {!isNew && variant && <VariantExtras productId={productId} variant={variant} />}
    </Drawer>
  );
}
