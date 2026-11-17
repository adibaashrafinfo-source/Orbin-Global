import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Modal, Skeleton } from '@/design/components';
import { productApi, variantApi } from '@/features/catalog/api';
import { fmtQty } from '@/features/stock/format';
import { formatMoney } from '@/lib/money';
import type { PosCard, PosVariant } from '../types';
import { chipLabel } from './helpers';

/** FR-704 quick view: gallery, specs, fitment and cross-reference numbers of one product, with an add button per size. */
export function QuickView({ card, onClose, onAdd }: { card: PosCard | null; onClose: () => void; onAdd: (v: PosVariant) => void }) {
  const { t } = useTranslation();
  const [picked, setPicked] = useState<number | null>(null);
  const product = useQuery({ queryKey: ['pos', 'product', card?.product_id], queryFn: () => productApi.show(card?.product_id ?? 0), enabled: card !== null, staleTime: 60_000 });
  const variantId = picked ?? card?.variants[0]?.id ?? null;
  const detail = useQuery({ queryKey: ['pos', 'variant', variantId], queryFn: () => variantApi.show(variantId ?? 0), enabled: variantId !== null, staleTime: 60_000 });
  const images = product.data?.images ?? [];
  const [shown, setShown] = useState(0);
  const main = images[shown] ?? images[0];
  return (
    <Modal open={card !== null} onOpenChange={(o) => { if (!o) { setPicked(null); setShown(0); onClose(); } }} className="max-w-4xl" title={card?.name ?? ''} description={card ? `${card.code}${card.brand ? ` · ${card.brand}` : ''}${card.quality ? ` · ${card.quality}` : ''}` : undefined}>
      {card && (
        <div className="grid gap-5 md:grid-cols-[320px_minmax(0,1fr)]">
          <div>
            <div className="aspect-[4/3] overflow-hidden rounded-card bg-navy-50">{main ? <img src={main.url} alt={main.alt_text ?? card.name} className="h-full w-full object-cover" /> : product.isLoading ? <Skeleton className="h-full" /> : <div className="flex h-full items-center justify-center text-xs text-ink-muted">{t('pos.noImage')}</div>}</div>
            {images.length > 1 && <ul className="mt-2 flex gap-1.5">{images.slice(0, 5).map((im, i) => <li key={im.id}><button type="button" aria-label={t('pos.showImage', { n: i + 1 })} aria-current={i === shown} onClick={() => setShown(i)} className="h-12 w-16 overflow-hidden rounded-input border border-navy-200 aria-[current=true]:border-orange-500 focus-visible:focus-ring-light"><img src={im.thumb_url ?? im.url} alt="" className="h-full w-full object-cover" /></button></li>)}</ul>}
            {card.warranty_months > 0 && <p className="mt-3 text-sm text-ink-muted">{t('pos.warranty', { months: card.warranty_months })}</p>}
            {product.data?.short_description && <p className="mt-2 text-sm text-ink-muted">{product.data.short_description}</p>}
          </div>
          <div className="flex min-w-0 flex-col gap-4">
            <table className="w-full text-sm">
              <caption className="sr-only">{t('pos.sizes')}</caption>
              <thead><tr className="text-start text-xs text-ink-muted"><th scope="col" className="py-1 text-start font-medium">{t('pos.size')}</th><th scope="col" className="text-start font-medium">{t('pos.position')}</th><th scope="col" className="text-end font-medium">{t('pos.price')}</th><th scope="col" className="text-end font-medium">{t('pos.stock')}</th><th scope="col"><span className="sr-only">{t('common.actions')}</span></th></tr></thead>
              <tbody>
                {card.variants.map((v) => {
                  const out = Number(v.available) <= 0;
                  return (
                    <tr key={v.id} className="border-t border-navy-100" aria-selected={v.id === variantId}>
                      <td className="py-2"><button type="button" className="text-start font-semibold text-navy-900 hover:underline focus-visible:focus-ring-light" onClick={() => setPicked(v.id)}>{chipLabel(v)}</button><div className="text-xs text-ink-muted">{v.sku}</div></td>
                      <td>{t(`pos.positions.${v.position}`, { defaultValue: v.position })}</td>
                      <td className="tabular text-end">{formatMoney(v.retail_price)}</td>
                      <td className={`tabular text-end ${out ? 'text-danger' : ''}`}>{out ? t('pos.out') : fmtQty(v.available)}</td>
                      <td className="ps-3 text-end"><Button size="sm" variant="secondary" disabled={out} onClick={() => onAdd(v)}>{t('pos.add')}</Button></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            <div className="grid gap-4 sm:grid-cols-2">
              <section aria-label={t('pos.fitment')}>
                <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-muted">{t('pos.fitment')}</h4>
                {detail.isLoading ? <Skeleton className="h-16" /> : (detail.data?.fitments ?? []).length === 0 ? <p className="text-sm text-ink-muted">{t('pos.noFitment')}</p> : (
                  <ul className="space-y-1 text-sm">{detail.data?.fitments?.map((f) => <li key={f.id}>{f.make} {f.model} <span className="text-ink-muted">{f.year_from}{f.year_to ? `–${f.year_to}` : '+'}</span></li>)}</ul>
                )}
              </section>
              <section aria-label={t('pos.crossRefs')}>
                <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-muted">{t('pos.crossRefs')}</h4>
                {detail.isLoading ? <Skeleton className="h-16" /> : (detail.data?.cross_references ?? []).length === 0 ? <p className="text-sm text-ink-muted">{t('pos.noCrossRefs')}</p> : (
                  <ul className="flex flex-wrap gap-1.5">{detail.data?.cross_references?.map((x) => <li key={x.id}><Badge tone="neutral">{x.number}</Badge></li>)}</ul>
                )}
              </section>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}
