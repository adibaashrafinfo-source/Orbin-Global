import { ImageOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Badge } from '@/design/components';
import type { Product } from '../types';
import { priceRange } from './priceRange';


/** Product tile with the Mirror Shine hover sweep (design system: product cards). */
export function ProductCard({ product }: { product: Product }) {
  const { t } = useTranslation();
  return (
    <Link
      to={`/products/${product.id}`}
      className="mirror-shine group relative flex h-full flex-col overflow-hidden rounded-card border border-navy-100 bg-surface shadow-sm transition-shadow duration-base hover:shadow-md focus-visible:focus-ring-light"
    >
      <div className="relative flex aspect-[4/3] items-center justify-center bg-navy-50">
        {product.primary_image_url ? (
          <img src={product.primary_image_url} alt="" loading="lazy" className="h-full w-full object-cover" />
        ) : (
          <ImageOff className="h-8 w-8 text-ink-subtle" aria-hidden="true" />
        )}
        {!product.is_active && <Badge tone="warning" className="absolute start-2 top-2">{t('products.draft')}</Badge>}
      </div>
      <div className="flex flex-1 flex-col gap-1 p-3">
        <p className="truncate font-heading text-sm font-bold text-navy-900">{product.name_en}</p>
        <p className="truncate text-xs text-ink-muted">{product.code} · {product.category ?? '—'}</p>
        {product.stock_total !== null && <p className={`text-xs font-medium ${Number(product.stock_total) > 0 ? 'text-success' : 'text-danger'}`}>{Number(product.stock_total) > 0 ? t('products.inStock', { qty: String(Number(product.stock_total)) }) : t('inventory.stockStatus.out')}</p>}
        <div className="mt-auto flex items-end justify-between pt-2">
          <span className="tabular text-sm font-semibold text-ink">{priceRange(product)}</span>
          <span className="text-xs text-ink-subtle">{t('products.variantsCount', { count: product.variants_count ?? 0 })}</span>
        </div>
      </div>
    </Link>
  );
}
