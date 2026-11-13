import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Card, CardBody, EmptyState } from '@/design/components';
import { KEYS, productApi, variantApi } from '../api';
import { useCustomFields } from '../hooks';
import type { CustomFieldDefRow } from '../types';
import { serverFieldError, useApiError } from '../useApiError';
import { useDerivedState } from '../util';
import { CustomFieldInput } from './CustomFieldInputs';
import { cleanCustom, type CustomValues } from './customFieldUtils';
import type { StepProps } from './steps';

function FieldsForm({ defs, initial, canManage, save, pending, error }: { defs: CustomFieldDefRow[]; initial: CustomValues; canManage: boolean; save: (v: CustomValues) => void; pending: boolean; error: unknown }) {
  const { t } = useTranslation();
  const [values, setValues] = useState<CustomValues>(initial);
  const dirty = JSON.stringify(cleanCustom(values)) !== JSON.stringify(cleanCustom(initial));
  return (
    <div className="grid gap-4 md:grid-cols-2">
      {defs.map((d) => <div key={d.id} className={d.field_type === 'long_text' || d.field_type === 'multi_select' ? 'md:col-span-2' : ''}><CustomFieldInput def={d} value={values[d.key]} disabled={!canManage} error={serverFieldError(t, error, `custom.${d.key}`)} onChange={(v) => setValues((s) => ({ ...s, [d.key]: v }))} /></div>)}
      {canManage && <div className="flex justify-end md:col-span-2"><Button disabled={!dirty} loading={pending} onClick={() => save(cleanCustom(values))}>{t('common.save')}</Button></div>}
    </div>
  );
}

/** Step 7. Admin-defined fields for the product and for each variant (FR-203). Required fields block publishing, not saving. */
export function CustomFieldsStep({ product, canManage }: StepProps) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const productDefs = (useCustomFields('product').data ?? []).filter((d) => d.is_active);
  const variantDefs = (useCustomFields('variant').data ?? []).filter((d) => d.is_active);
  const [variantError, setVariantError] = useDerivedState<{ id: number; error: unknown } | null>(product.id, () => null);
  const refresh = () => void client.invalidateQueries({ queryKey: KEYS.product(product.id) });
  const saveProduct = useMutation({ mutationFn: (custom: CustomValues) => productApi.update(product.id, { custom }), onSuccess: refresh, onError });
  const saveVariant = useMutation({ mutationFn: ({ id, custom }: { id: number; custom: CustomValues }) => variantApi.update(id, { custom }), onSuccess: () => { setVariantError(null); refresh(); }, onError: (e, v) => { setVariantError({ id: v.id, error: e }); onError(e); } });
  if (productDefs.length === 0 && variantDefs.length === 0) return <EmptyState body={t('products.custom.none')} />;
  return (
    <div className="grid gap-5">
      {productDefs.length > 0 && <Card><CardBody>
        <h2 className="mb-3 font-heading text-lg font-bold text-navy-900">{t('products.custom.product')}</h2>
        <FieldsForm key={product.updated_at} defs={productDefs} initial={product.custom ?? {}} canManage={canManage} save={(c) => saveProduct.mutate(c)} pending={saveProduct.isPending} error={saveProduct.error} />
      </CardBody></Card>}
      {variantDefs.length > 0 && (product.variants ?? []).map((v) => (
        <Card key={v.id}><CardBody>
          <h2 className="mb-3 font-heading text-base font-bold text-navy-900">{t('products.custom.variant')} <code className="text-sm font-semibold">{v.sku}</code></h2>
          <FieldsForm key={v.sku + JSON.stringify(v.custom)} defs={variantDefs} initial={v.custom ?? {}} canManage={canManage} save={(c) => saveVariant.mutate({ id: v.id, custom: c })} pending={saveVariant.isPending && saveVariant.variables?.id === v.id} error={variantError?.id === v.id ? variantError.error : null} />
        </CardBody></Card>
      ))}
    </div>
  );
}
