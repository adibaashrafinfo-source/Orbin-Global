import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Copy, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Badge, Button, ConfirmDialog, ErrorState, PageHeader, PageSkeleton, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { KEYS, productApi } from '../api';
import { useMaster } from '../hooks';
import { useApiError } from '../useApiError';
import { BasicStep } from './BasicStep';
import { CustomFieldsStep } from './CustomFieldsStep';
import { FitmentStep } from './FitmentStep';
import { ImagesStep } from './ImagesStep';
import { PricingStep } from './PricingStep';
import { ReviewStep } from './ReviewStep';
import { StockStep } from './StockStep';
import { isStep, STEPS, type StepKey, type StepProps } from './steps';
import { VariantsStep } from './VariantsStep';

/** FR-302 Product editor: eight steps, one page. The product exists as a draft from step 1 and is published from the Review step. */
export function ProductEditorPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const productId = id ? Number(id) : null;
  const navigate = useNavigate();
  const client = useQueryClient();
  const { can } = useSession();
  const onError = useApiError();
  const [params, setParams] = useSearchParams();
  const [confirmDelete, setConfirmDelete] = useState(false);
  const units = useMaster('units');
  const taxes = useMaster('tax-rates');

  const query = useQuery({ queryKey: KEYS.product(productId ?? 0), queryFn: () => productApi.show(productId as number), enabled: productId !== null });
  const product = query.data ?? null;
  const rawStep = params.get('step');
  const step: StepKey = productId === null ? 'basic' : isStep(rawStep) ? rawStep : 'basic';

  const clone = useMutation({
    mutationFn: () => productApi.clone(productId as number),
    onSuccess: (copy) => { void client.invalidateQueries({ queryKey: KEYS.products }); toast.success(t('products.cloned')); navigate(`/products/${copy.id}`); },
    onError,
  });
  const remove = useMutation({
    mutationFn: () => productApi.remove(productId as number),
    onSuccess: () => { void client.invalidateQueries({ queryKey: KEYS.products }); toast.success(t('masterData.deleted')); navigate('/products'); },
    onError: (e) => { setConfirmDelete(false); onError(e); },
  });

  if (productId !== null && query.isLoading) return <PageSkeleton />;
  if (productId !== null && query.error) {
    const missing = query.error instanceof ApiError && query.error.status === 404;
    return <ErrorState title={missing ? t('errors.NOT_FOUND') : undefined} onRetry={() => void query.refetch()} requestId={query.error instanceof ApiError ? query.error.requestId : undefined} />;
  }
  if (productId === null && (units.isLoading || taxes.isLoading)) return <PageSkeleton />;

  const props: StepProps | null = product ? { product, canManage: can(['products.manage']), canPrice: can(['pricing.manage']), canCost: can(['products.view_cost']) } : null;
  const goto = (s: StepKey) => setParams(s === 'basic' ? {} : { step: s }, { replace: true });
  const title = product ? product.name_en : t('products.newProduct');

  return (
    <>
      <PageHeader
        title={title}
        description={product ? <span className="flex items-center gap-2"><code className="text-xs">{product.code}</code><Badge tone={product.is_active ? 'success' : 'warning'}>{product.is_active ? t('products.published') : t('products.draft')}</Badge></span> : t('products.newSubtitle')}
        crumbs={[{ label: t('products.title'), to: '/products' }, { label: title }]}
        actions={product && props?.canManage ? (
          <div className="flex gap-2">
            <Button variant="secondary" size="sm" leftIcon={<Copy className="h-4 w-4" aria-hidden="true" />} loading={clone.isPending} onClick={() => clone.mutate()}>{t('products.clone')}</Button>
            <Button variant="secondary" size="sm" leftIcon={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmDelete(true)}>{t('common.delete')}</Button>
          </div>
        ) : undefined}
      />
      <ol className="mb-6 flex flex-wrap gap-1 border-b border-navy-100 pb-3" aria-label={t('products.steps.label')}>
        {STEPS.map((s, i) => {
          const disabled = productId === null && s !== 'basic';
          const current = s === step;
          return (
            <li key={s}>
              <button type="button" disabled={disabled} aria-current={current ? 'step' : undefined} onClick={() => goto(s)}
                className={cn('flex h-9 items-center gap-2 rounded-input px-3 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light disabled:cursor-not-allowed disabled:opacity-50', current ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-100 hover:text-ink')}>
                <span className={cn('flex h-5 w-5 items-center justify-center rounded-pill text-[11px]', current ? 'bg-surface/20' : 'bg-navy-100 text-navy-800')}>{!disabled && product && STEPS.indexOf(step) > i ? <Check className="h-3 w-3" aria-hidden="true" /> : i + 1}</span>
                {t(`products.steps.${s}`)}
              </button>
            </li>
          );
        })}
      </ol>
      {step === 'basic' && <BasicStep key={productId ?? 'new'} product={product} canManage={productId === null ? can(['products.manage']) : (props?.canManage ?? false)} canPrice={false} canCost={false} />}
      {props && step === 'variants' && <VariantsStep {...props} />}
      {props && step === 'images' && <ImagesStep {...props} />}
      {props && step === 'fitment' && <FitmentStep {...props} />}
      {props && step === 'pricing' && <PricingStep {...props} />}
      {props && step === 'stock' && <StockStep {...props} />}
      {props && step === 'custom' && <CustomFieldsStep {...props} />}
      {props && step === 'review' && <ReviewStep {...props} onGoto={goto} />}
      <ConfirmDialog open={confirmDelete} onOpenChange={setConfirmDelete} danger title={t('products.deleteTitle')} body={t('products.deleteBody', { name: product?.name_en ?? '' })} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => remove.mutate()} />
    </>
  );
}
