import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { AlertTriangle, CheckCircle2, ImageOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Card, CardBody, ConfirmDialog, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { KEYS, productApi } from '../api';
import { useCustomFields } from '../hooks';
import type { Product } from '../types';
import { useApiError } from '../useApiError';
import { str } from '../util';
import type { StepKey, StepProps } from './steps';

interface Check { key: string; ok: boolean; blocking: boolean; step: StepKey }

function checks(p: Product, missingRequired: number): Check[] {
  const active = (p.variants ?? []).filter((v) => v.is_active);
  return [
    { key: 'variant', ok: active.length > 0, blocking: true, step: 'variants' },
    { key: 'required', ok: missingRequired === 0, blocking: true, step: 'custom' },
    { key: 'prices', ok: active.length > 0 && active.every((v) => Number(v.retail_price) > 0), blocking: false, step: 'pricing' },
    { key: 'image', ok: (p.images ?? []).length > 0, blocking: false, step: 'images' },
    { key: 'fitment', ok: active.some((v) => (v.fitments ?? []).length > 0), blocking: false, step: 'fitment' },
    { key: 'barcode', ok: active.length > 0 && active.every((v) => !!v.barcode), blocking: false, step: 'variants' },
  ];
}

/** What a POS tile will show for this product, so the owner sees the outcome before publishing. */
function PosPreview({ product }: { product: Product }) {
  const { t } = useTranslation();
  const prices = (product.variants ?? []).filter((v) => v.is_active).map((v) => Number(v.retail_price));
  const lo = prices.length ? Math.min(...prices) : null;
  const hi = prices.length ? Math.max(...prices) : null;
  const image = (product.images ?? []).find((i) => i.is_primary) ?? (product.images ?? [])[0];
  return (
    <div className="mirror-shine relative w-64 overflow-hidden rounded-card border border-navy-100 bg-surface shadow-md" aria-label={t('products.review.posPreview')}>
      <div className="flex aspect-[4/3] items-center justify-center bg-navy-50">{image ? <img src={image.thumb_url ?? image.url} alt="" className="h-full w-full object-cover" /> : <ImageOff className="h-8 w-8 text-ink-muted" aria-hidden="true" />}</div>
      <div className="p-3">
        <p className="truncate font-heading text-sm font-bold text-navy-900">{product.name_en}</p>
        <p className="truncate text-xs text-ink-muted">{product.code}</p>
        <p className="tabular mt-2 text-sm font-semibold">{lo === null ? '—' : lo === hi ? formatMoney(lo) : `${formatMoney(lo)} – ${formatMoney(hi ?? 0, { symbol: false })}`}</p>
        <p className="text-xs text-ink-muted">{t('products.variantsCount', { count: prices.length })}</p>
      </div>
    </div>
  );
}

/** Step 8. Readiness checklist, POS card preview and the publish action (the page's one accent button). */
export function ReviewStep({ product, canManage, onGoto }: StepProps & { onGoto: (s: StepKey) => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const pDefs = (useCustomFields('product').data ?? []).filter((d) => d.is_active && d.is_required);
  const vDefs = (useCustomFields('variant').data ?? []).filter((d) => d.is_active && d.is_required);
  const filled = (c: Record<string, unknown> | undefined, k: string) => { const v = c?.[k]; return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0); };
  const missing = pDefs.filter((d) => !filled(product.custom, d.key)).length + (product.variants ?? []).filter((v) => v.is_active).reduce((n, v) => n + vDefs.filter((d) => !filled(v.custom, d.key)).length, 0);
  const list = checks(product, missing);
  const blocked = list.some((c) => c.blocking && !c.ok);

  const done = (p: Product, msg: string) => { client.setQueryData(KEYS.product(p.id), p); void client.invalidateQueries({ queryKey: KEYS.products }); toast.success(msg); };
  const publish = useMutation({ mutationFn: () => productApi.publish(product.id), onSuccess: (p) => done(p, t('products.review.published')), onError });
  const [stockWarning, setStockWarning] = useState<string | null>(null);
  const deactivate = useMutation({
    mutationFn: (confirm: boolean) => productApi.deactivate(product.id, confirm),
    onSuccess: (p) => { setStockWarning(null); done(p, t('products.review.deactivated')); },
    onError: (e) => { if (e instanceof ApiError && e.code === 'HAS_STOCK') setStockWarning(str(e.context.units)); else onError(e); },
  });

  return (
    <section aria-labelledby="review-h" className="grid gap-6 lg:grid-cols-[1fr_auto]">
      <Card><CardBody>
        <h2 id="review-h" className="mb-3 font-heading text-lg font-bold text-navy-900">{t('products.review.title')}</h2>
        <ul className="grid gap-2">
          {list.map((c) => (
            <li key={c.key} className="flex items-center justify-between gap-3 rounded-input px-2 py-1.5 text-sm">
              <span className="flex items-center gap-2">
                {c.ok ? <CheckCircle2 className="h-4 w-4 text-success" aria-hidden="true" /> : <AlertTriangle className={c.blocking ? 'h-4 w-4 text-danger' : 'h-4 w-4 text-warning'} aria-hidden="true" />}
                <span>{t(`products.review.checks.${c.key === 'required' && !c.ok ? 'requiredMissing' : c.key}`, { count: missing })}</span>
                {!c.ok && <Badge tone={c.blocking ? 'danger' : 'warning'}>{c.blocking ? t('products.review.required') : t('products.review.advice')}</Badge>}
              </span>
              {!c.ok && <Button variant="ghost" size="sm" onClick={() => onGoto(c.step)}>{t('products.review.fix')}</Button>}
            </li>
          ))}
        </ul>
        {canManage && (
          <div className="mt-5 flex items-center justify-end gap-3 border-t border-navy-100 pt-4">
            {product.is_active
              ? <Button variant="secondary" loading={deactivate.isPending} onClick={() => deactivate.mutate(false)}>{t('products.review.deactivate')}</Button>
              : <Button variant="accent" disabled={blocked} loading={publish.isPending} onClick={() => publish.mutate()}>{t('products.review.publish')}</Button>}
          </div>
        )}
      </CardBody></Card>
      <PosPreview product={product} />
      <ConfirmDialog open={stockWarning !== null} onOpenChange={(o) => !o && setStockWarning(null)} danger title={t('products.review.deactivateTitle')} body={t('products.review.hasStock', { units: stockWarning ?? '' })} confirmLabel={t('products.review.deactivateAnyway')} loading={deactivate.isPending} onConfirm={() => deactivate.mutate(true)} />
    </section>
  );
}
