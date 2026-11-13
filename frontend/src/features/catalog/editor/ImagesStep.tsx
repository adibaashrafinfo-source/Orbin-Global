import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, ImagePlus, Star, Trash2 } from 'lucide-react';
import { useRef, useState, type DragEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, IconButton, Select, toast } from '@/design/components';
import { cn } from '@/lib/cn';
import { imageApi, KEYS } from '../api';
import { IMAGE_LABELS, type ImageLabel, type ProductImage } from '../types';
import { useApiError } from '../useApiError';
import type { StepProps } from './steps';

const PRODUCT_SCOPE = '__product__';
const MAX_PER_SCOPE = 8;

/** Step 3. Upload (drag and drop or browse), label, set primary and reorder images per product or variant (FR-305). */
export function ImagesStep({ product, canManage }: StepProps) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const input = useRef<HTMLInputElement>(null);
  const [scope, setScope] = useState(PRODUCT_SCOPE);
  const [label, setLabel] = useState<ImageLabel>('front');
  const [over, setOver] = useState(false);
  const [dragId, setDragId] = useState<number | null>(null);
  const variantId = scope === PRODUCT_SCOPE ? null : Number(scope);
  const images = (product.images ?? []).filter((i) => (i.variant_id ?? null) === variantId).sort((a, b) => a.sort_order - b.sort_order);
  const refresh = () => void client.invalidateQueries({ queryKey: KEYS.product(product.id) });

  const upload = useMutation({
    mutationFn: async (files: File[]) => { for (const f of files) await imageApi.upload(product.id, f, { variantId, label }); },
    onSuccess: () => { refresh(); toast.success(t('products.images.uploaded')); },
    onError: (e) => { refresh(); onError(e); },
  });
  const update = useMutation({ mutationFn: ({ id, label: l }: { id: number; label: ImageLabel }) => imageApi.update(id, { label: l }), onSuccess: refresh, onError });
  const primary = useMutation({ mutationFn: (id: number) => imageApi.primary(id), onSuccess: refresh, onError });
  const remove = useMutation({ mutationFn: (id: number) => imageApi.remove(id), onSuccess: refresh, onError });
  const reorder = useMutation({ mutationFn: (ids: number[]) => imageApi.reorder(product.id, ids), onSuccess: refresh, onError });

  const pick = (list: FileList | null) => {
    const files = Array.from(list ?? []).filter((f) => f.type.startsWith('image/'));
    if (files.length === 0) return;
    if (images.length + files.length > MAX_PER_SCOPE) { toast.error(t('errors.IMAGE_LIMIT', { max: MAX_PER_SCOPE })); return; }
    upload.mutate(files);
  };
  const move = (img: ProductImage, to: number) => {
    const ids = images.map((i) => i.id);
    const from = ids.indexOf(img.id);
    if (to < 0 || to >= ids.length || from < 0) return;
    ids.splice(to, 0, ids.splice(from, 1)[0] as number);
    reorder.mutate(ids);
  };
  const onDrop = (e: DragEvent, target: ProductImage) => {
    e.preventDefault();
    const dragged = images.find((i) => i.id === dragId);
    setDragId(null);
    if (dragged && dragged.id !== target.id) move(dragged, images.findIndex((i) => i.id === target.id));
  };
  const scopes = [{ value: PRODUCT_SCOPE, label: t('products.images.wholeProduct') }, ...(product.variants ?? []).map((v) => ({ value: String(v.id), label: v.sku }))];

  return (
    <section aria-labelledby="images-h" className="grid gap-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h2 id="images-h" className="font-heading text-lg font-bold text-navy-900">{t('products.steps.images')}</h2>
        <div className="flex gap-3">
          <div className="w-56"><Select label={t('products.images.scope')} value={scope} onChange={setScope} options={scopes} /></div>
          {canManage && <div className="w-40"><Select label={t('products.images.newLabel')} value={label} onChange={(v) => setLabel(v as ImageLabel)} options={IMAGE_LABELS.map((l) => ({ value: l, label: t(`products.images.labels.${l}`) }))} /></div>}
        </div>
      </div>

      {canManage && (
        <label
          onDragOver={(e) => { e.preventDefault(); setOver(true); }} onDragLeave={() => setOver(false)}
          onDrop={(e) => { e.preventDefault(); setOver(false); pick(e.dataTransfer.files); }}
          className={cn('flex cursor-pointer flex-col items-center justify-center gap-2 rounded-card border-2 border-dashed px-6 py-8 text-center transition-colors duration-fast focus-within:focus-ring-light', over ? 'border-navy-600 bg-navy-50' : 'border-navy-200 bg-surface hover:bg-navy-50')}
        >
          <ImagePlus className="h-7 w-7 text-navy-700" aria-hidden="true" />
          <span className="text-sm font-medium text-ink">{upload.isPending ? t('common.saving') : t('products.images.drop')}</span>
          <span className="text-xs text-ink-muted">{t('products.images.rules', { max: MAX_PER_SCOPE })}</span>
          <input ref={input} type="file" multiple accept="image/jpeg,image/png,image/webp" className="sr-only" data-testid="image-input" onChange={(e) => { pick(e.target.files); e.target.value = ''; }} />
        </label>
      )}

      {images.length === 0 ? <p className="text-sm text-ink-muted">{t('products.images.empty')}</p> : (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label={t('products.steps.images')}>
          {images.map((img, i) => (
            <li key={img.id} draggable={canManage} onDragStart={() => setDragId(img.id)} onDragOver={(e) => e.preventDefault()} onDrop={(e) => onDrop(e, img)}
              className={cn('overflow-hidden rounded-card border bg-surface', dragId === img.id ? 'opacity-50' : '', img.is_primary ? 'border-orange-400' : 'border-navy-100')}>
              <div className="relative aspect-square bg-navy-50"><img src={img.thumb_url ?? img.url} alt={img.alt_text ?? t(`products.images.labels.${img.label}`)} className="h-full w-full object-cover" />
                {img.is_primary && <Badge tone="orange" className="absolute start-2 top-2">{t('products.images.primary')}</Badge>}</div>
              <div className="grid gap-2 p-2">
                <Select aria-label={t('products.images.label')} value={img.label} disabled={!canManage} onChange={(v) => update.mutate({ id: img.id, label: v as ImageLabel })} options={IMAGE_LABELS.map((l) => ({ value: l, label: t(`products.images.labels.${l}`) }))} />
                {canManage && (
                  <div className="flex justify-between">
                    <IconButton label={t('products.images.makePrimary')} disabled={img.is_primary} onClick={() => primary.mutate(img.id)}><Star className="h-4 w-4" aria-hidden="true" /></IconButton>
                    <IconButton label={t('masterData.moveUp')} disabled={i === 0} onClick={() => move(img, i - 1)}><ArrowLeft className="h-4 w-4" aria-hidden="true" /></IconButton>
                    <IconButton label={t('masterData.moveDown')} disabled={i === images.length - 1} onClick={() => move(img, i + 1)}><ArrowRight className="h-4 w-4" aria-hidden="true" /></IconButton>
                    <IconButton label={t('common.delete')} onClick={() => remove.mutate(img.id)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>
                  </div>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
