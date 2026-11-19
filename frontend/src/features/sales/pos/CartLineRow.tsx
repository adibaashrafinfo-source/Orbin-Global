import { Minus, Percent, Plus, StickyNote, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, IconButton, Input } from '@/design/components';
import { cn } from '@/lib/cn';
import { formatMoney, fromPaisa } from '@/lib/money';
import type { PosConfig } from '../types';
import { chipLabel } from './helpers';
import { type CartCustomer, type CartLine, isLineOverridden, useCart } from './cartStore';
import { DiscountDialog } from './DiscountDialog';
import type { LineResult } from './pricing';
import { lineWarnings } from './warnings';

/** One cart line (FR-705): quantity stepper, price (editable with permission), discount, note and the line total. */
export function CartLineRow({ line, result, selected, config, customer, flash }: { line: CartLine; result: LineResult | undefined; selected: boolean; config: PosConfig | undefined; customer: CartCustomer | null; flash: boolean }) {
  const { t } = useTranslation();
  const cart = useCart();
  const [discount, setDiscount] = useState(false);
  const [noting, setNoting] = useState(false);
  const warnings = lineWarnings(line, config);
  const overridden = isLineOverridden(line, customer);
  const canPrice = config?.can.price_override ?? false;
  const name = line.variant.product;
  return (
    <li data-testid="cart-line" aria-current={selected} className={cn('flex flex-col gap-1.5 border-b border-navy-100 px-3 py-2.5', selected && 'bg-navy-50', flash && 'animate-row-flash')} onPointerDownCapture={() => cart.select(line.key)} onFocusCapture={() => cart.select(line.key)}>
      <div className="flex items-start gap-2.5">
        <div className="h-10 w-10 shrink-0 overflow-hidden rounded-input bg-navy-50">{line.variant.image ? <img src={line.variant.image} alt="" className="h-full w-full object-cover" /> : null}</div>
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-semibold text-navy-900">{name}</div>
          <div className="truncate text-xs text-ink-muted">{chipLabel(line.variant)} · {line.variant.sku}</div>
          {warnings.length > 0 && <div className="mt-1 flex flex-wrap gap-1">{warnings.map((w) => <Badge key={w} tone={w === 'belowFloor' ? 'danger' : 'orange'}>{t(`pos.warn.${w}`, { available: line.variant.available })}</Badge>)}</div>}
        </div>
        <IconButton label={t('pos.removeLine', { name })} onClick={() => cart.remove(line.key)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>
      </div>
      <div className="flex items-center gap-1.5">
        <div role="group" aria-label={t('pos.quantity', { name })} className="flex shrink-0 items-center rounded-input border border-navy-200">
          <button type="button" aria-label={t('pos.qtyDown')} className="flex h-8 w-7 items-center justify-center rounded-s-input hover:bg-navy-50 focus-visible:focus-ring-light" onClick={() => cart.step(line.key, -1)}><Minus className="h-3.5 w-3.5" aria-hidden="true" /></button>
          <input aria-label={t('pos.qty')} inputMode="decimal" value={line.qty} onChange={(e) => cart.setQty(line.key, e.target.value.replace(/[^\d.]/g, ''))} className="tabular h-8 w-12 border-x border-navy-200 bg-surface text-center text-base md:text-sm focus:outline-none focus-visible:focus-ring-light" />
          <button type="button" aria-label={t('pos.qtyUp')} className="flex h-8 w-7 items-center justify-center rounded-e-input hover:bg-navy-50 focus-visible:focus-ring-light" onClick={() => cart.step(line.key, 1)}><Plus className="h-3.5 w-3.5" aria-hidden="true" /></button>
        </div>
        <span className="text-ink-muted" aria-hidden="true">×</span>
        {canPrice ? (
          <div className="w-24 shrink-0"><Input aria-label={t('pos.unitPrice', { name })} inputMode="decimal" value={line.unitPrice} onChange={(e) => cart.setUnitPrice(line.key, e.target.value.replace(/[^\d.]/g, ''))} className={cn('tabular h-8 text-end', overridden && 'border-orange-500')} /></div>
        ) : <span className="tabular shrink-0 text-sm">{formatMoney(line.unitPrice, { symbol: false })}</span>}
        <button type="button" aria-label={t('pos.lineDiscount', { name })} onClick={() => setDiscount(true)} className={cn('flex h-8 shrink-0 items-center gap-1 rounded-pill border px-2 text-xs font-semibold focus-visible:focus-ring-light', line.discountType === 'none' ? 'border-navy-200 text-ink-muted hover:bg-navy-50' : 'border-orange-300 bg-orange-50 text-orange-800')}>
          <Percent className="h-3 w-3" aria-hidden="true" />{line.discountType === 'none' ? <span className="sr-only">{t('pos.discount')}</span> : line.discountType === 'percent' ? `${line.discountValue}%` : formatMoney(line.discountValue, { symbol: false })}
        </button>
        <button type="button" aria-label={t('pos.lineNote', { name })} onClick={() => setNoting((n) => !n)} className={cn('flex h-8 w-8 items-center justify-center rounded-input hover:bg-navy-50 focus-visible:focus-ring-light', line.note ? 'text-orange-700' : 'text-ink-muted')}><StickyNote className="h-4 w-4" aria-hidden="true" /></button>
        <span className="tabular ms-auto min-w-[4.5rem] shrink-0 text-end text-sm font-bold text-navy-900">{result ? formatMoney(fromPaisa(result.lineTotal), { symbol: false }) : '—'}</span>
      </div>
      {noting && <div className="ps-[3.125rem]"><Input ref={(el) => el?.focus()} aria-label={t('pos.lineNote', { name })} placeholder={t('pos.notePlaceholder')} value={line.note} maxLength={255} onChange={(e) => cart.setLineNote(line.key, e.target.value)} /></div>}
      <DiscountDialog open={discount} title={t('pos.lineDiscount', { name })} type={line.discountType} value={line.discountValue} onClose={() => setDiscount(false)} onApply={(ty, v) => cart.setLineDiscount(line.key, ty, v)} />
    </li>
  );
}
