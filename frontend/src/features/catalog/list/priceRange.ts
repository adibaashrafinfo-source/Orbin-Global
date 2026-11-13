import { formatMoney } from '@/lib/money';
import type { Product } from '../types';

export function priceRange(p: Pick<Product, 'min_price' | 'max_price'>): string {
  if (p.min_price == null) return '—';
  if (p.max_price == null || p.max_price === p.min_price) return formatMoney(p.min_price);
  return `${formatMoney(p.min_price)} – ${formatMoney(p.max_price, { symbol: false })}`;
}
