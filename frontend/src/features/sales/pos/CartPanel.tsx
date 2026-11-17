import { Car, ClipboardList, PauseCircle, Truck, User, Undo2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, EmptyState } from '@/design/components';
import { cn } from '@/lib/cn';
import { formatMoney, fromPaisa } from '@/lib/money';
import type { PosConfig } from '../types';
import { AnimatedMoney } from './AnimatedMoney';
import { CartLineRow } from './CartLineRow';
import { useCart } from './cartStore';
import type { PriceResult } from './pricing';
import { overDiscountLimit } from './warnings';

export interface CartActions {
  onPay: () => void;
  onHold: () => void;
  onQuote: () => void;
  onCustomer: () => void;
  onVehicle: () => void;
  onHeld: () => void;
  onCartDiscount: () => void;
  onDelivery: () => void;
  onDetails: () => void;
}

/** The right-hand cart (PRD §8.3): customer and vehicle chips, the lines, the running summary and the three actions. */
export function CartPanel({ config, totals, heldCount, drawerOpen, flashKey, actions, busy }: { config: PosConfig | undefined; totals: PriceResult; heldCount: number; drawerOpen: boolean; flashKey: string | null; actions: CartActions; busy: boolean }) {
  const { t } = useTranslation();
  const { lines, selected, customer, vehicle, cartDiscountType, cartDiscountValue, lastRemoved, undoRemove, channel, salespersonId } = useCart();
  const salesperName = salespersonId ? (config?.salespeople.find((p) => p.id === salespersonId)?.full_name ?? '') : t('pos.salespersonMe');
  const empty = lines.length === 0;
  const vehicleText = [vehicle.reg_no, [vehicle.make, vehicle.model, vehicle.year].filter(Boolean).join(' ')].filter(Boolean).join(' · ');
  const over = overDiscountLimit(totals.subtotal, totals.lineDiscountTotal, totals.cartDiscount, config);
  return (
    <aside aria-label={t('pos.cart')} className="flex flex-col rounded-card bg-surface shadow-lg lg:h-full lg:min-h-0 lg:overflow-hidden">
      <div className="flex items-center gap-2 border-b border-navy-100 p-3">
        <button type="button" onClick={actions.onCustomer} aria-label={t('pos.changeCustomer')} className="flex min-w-0 max-w-[45%] items-center gap-1.5 rounded-pill border border-navy-200 px-3 py-1.5 text-sm font-medium hover:bg-navy-50 focus-visible:focus-ring-light"><User className="h-4 w-4 shrink-0 text-navy-600" aria-hidden="true" /><span className="truncate" data-testid="cart-customer">{customer?.name ?? t('pos.walkIn')}</span>{customer && !customer.is_walk_in && customer.price_tier !== 'retail' && <Badge tone="orange">{t(`customers.tiers.${customer.price_tier}`)}</Badge>}</button>
        <button type="button" onClick={actions.onVehicle} aria-label={t('pos.changeVehicle')} className="flex min-w-0 max-w-[35%] items-center gap-1.5 rounded-pill border border-navy-200 px-3 py-1.5 text-sm hover:bg-navy-50 focus-visible:focus-ring-light"><Car className="h-4 w-4 shrink-0 text-navy-600" aria-hidden="true" /><span className="truncate">{vehicleText || t('pos.addVehicle')}</span></button>
        <button type="button" onClick={actions.onHeld} aria-label={t('pos.heldBills', { count: heldCount })} className="ms-auto flex shrink-0 items-center gap-1.5 rounded-pill px-2.5 py-1.5 text-sm text-navy-700 hover:bg-navy-100 focus-visible:focus-ring-light"><ClipboardList className="h-4 w-4" aria-hidden="true" />{t('pos.held')} <Badge tone={heldCount > 0 ? 'orange' : 'neutral'}>{heldCount}</Badge></button>
      </div>
      <button type="button" onClick={actions.onDetails} aria-label={t('pos.saleDetails')} className="flex items-center justify-between gap-2 border-b border-navy-100 px-3 py-2 text-start text-xs text-ink-muted hover:bg-navy-50 focus-visible:focus-ring-light">
        <span className="truncate">{t(`pos.channels.${channel}`)} · {salesperName}</span>
        <span className="shrink-0 font-semibold text-navy-700">{t('pos.saleDetails')}</span>
      </button>
      <div className="min-h-0 flex-1 lg:overflow-y-auto" data-testid="cart-lines">
        {empty ? <div className="p-4"><EmptyState title={t('pos.cartEmptyTitle')} body={t('pos.cartEmptyBody')} /></div> : (
          <ul aria-label={t('pos.cartLines')}>{lines.map((l, i) => <CartLineRow key={l.key} line={l} result={totals.lines[i]} selected={selected === l.key} config={config} customer={customer} flash={flashKey === l.key} />)}</ul>
        )}
        {lastRemoved && <button type="button" onClick={undoRemove} className="mx-3 my-2 inline-flex items-center gap-1.5 rounded-pill bg-navy-100 px-3 py-1 text-xs font-semibold text-navy-900 hover:bg-navy-200 focus-visible:focus-ring-light"><Undo2 className="h-3.5 w-3.5" aria-hidden="true" />{t('pos.undoRemove', { name: lastRemoved.variant.product })}</button>}
      </div>
      <dl className="border-t border-navy-100 px-4 py-2 text-sm" aria-label={t('pos.summary')}>
        <Row label={t('pos.subtotal')} value={formatMoney(fromPaisa(totals.subtotal))} />
        {totals.lineDiscountTotal > 0n && <Row label={t('pos.lineDiscounts')} value={`− ${formatMoney(fromPaisa(totals.lineDiscountTotal))}`} />}
        <Row label={<button type="button" onClick={actions.onCartDiscount} className="text-navy-700 underline-offset-2 hover:underline focus-visible:focus-ring-light">{t('pos.cartDiscount')} <kbd className="text-xs text-ink-muted">F8</kbd></button>} value={cartDiscountType === 'none' ? '—' : `− ${formatMoney(fromPaisa(totals.cartDiscount))}${cartDiscountType === 'percent' ? ` (${cartDiscountValue}%)` : ''}`} />
        {over && <div role="alert" className="py-1 text-xs font-medium text-orange-700">{t('pos.overLimit', { percent: config?.settings.cashier_max_discount_percent ?? 0 })}</div>}
        <Row label={t('pos.vat')} value={formatMoney(fromPaisa(totals.taxTotal))} />
        <Row label={<button type="button" onClick={actions.onDelivery} className="inline-flex items-center gap-1 text-navy-700 underline-offset-2 hover:underline focus-visible:focus-ring-light"><Truck className="h-3.5 w-3.5" aria-hidden="true" />{t('pos.delivery')} <kbd className="text-xs text-ink-muted">F6</kbd></button>} value={totals.delivery > 0n ? formatMoney(fromPaisa(totals.delivery)) : '—'} />
        {totals.roundOff !== 0n && <Row label={t('pos.roundOff')} value={formatMoney(fromPaisa(totals.roundOff))} />}
      </dl>
      <div className="mx-3 mb-2 flex items-center justify-between rounded-card bg-navy-900 px-4 py-3 text-surface" aria-live="polite">
        <span className="text-sm font-semibold">{t('pos.grandTotal')}</span>
        <AnimatedMoney paisa={totals.grandTotal} className="tabular text-2xl font-extrabold" />
      </div>
      <div className="flex flex-col gap-2 p-3 pt-1">
        <Button variant={drawerOpen ? 'secondary' : 'accent'} size="xl" block className={cn(empty && 'opacity-60')} disabled={empty || busy} onClick={actions.onPay} title="F9" data-testid="pay-button">{t('pos.payAmount', { amount: formatMoney(fromPaisa(totals.grandTotal)) })}<kbd className="ms-2 text-xs font-normal opacity-70">F9</kbd></Button>
        <div className="flex gap-2">
          <Button variant="ghost" className="flex-1" disabled={empty || busy} leftIcon={<PauseCircle className="h-4 w-4" aria-hidden="true" />} onClick={actions.onHold} title="F10">{t('pos.hold')}</Button>
          <Button variant="secondary" className="flex-1" disabled={empty || busy} onClick={actions.onQuote}>{t('pos.quotation')}</Button>
        </div>
      </div>
    </aside>
  );
}

function Row({ label, value }: { label: React.ReactNode; value: string }) {
  return <div className="flex items-center justify-between py-0.5"><dt className="text-ink-muted">{label}</dt><dd className="tabular font-medium">{value}</dd></div>;
}
