import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { ConfirmDialog, ErrorState, PageSkeleton, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { apiMessage, useApiError } from '@/features/catalog/useApiError';
import { ApiError } from '@/lib/api';
import { customerApi, posApi, quotationApi, SALES_KEYS, saleApi } from '../api';
import type { PosCard, PosVariant } from '../types';
import { ApprovalDialog } from './ApprovalDialog';
import { CartPanel } from './CartPanel';
import { type CartCustomer, type LoadedCart, useCart } from './cartStore';
import { CustomerPicker } from './CustomerPicker';
import { AmountDialog, DiscountDialog } from './DiscountDialog';
import { printPdf } from './files';
import { HeldBills } from './HeldBills';
import { PayDrawer } from './PayDrawer';
import { type GridFilters, ProductGrid } from './ProductGrid';
import { PosToolbar } from './PosToolbar';
import { QuickView } from './QuickView';
import { ShiftGate } from './ShiftGate';
import { SuccessScreen } from './SuccessScreen';
import { EMPTY_FINDER, toCartCustomer, type Finder } from './helpers';
import { VehicleFinder } from './VehicleFinder';
import { VehicleDialog } from './VehicleDialog';
import { useCartTotals, usePosConfig } from './usePos';
import { useCheckout } from './useCheckout';
import { useShortcuts } from './useShortcuts';

type Dialog = 'customer' | 'vehicle' | 'held' | 'discount' | 'delivery' | 'pay' | null;

/** A short soft beep for a scan (FR-703); silent where the browser blocks audio. */
function beep(): void {
  try {
    const ctx = new AudioContext();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.frequency.value = 880;
    gain.gain.value = 0.04;
    osc.connect(gain).connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.07);
    osc.onended = () => void ctx.close();
  } catch {
    /* no audio available */
  }
}

/** The POS terminal (FR-700, PRD §8.3): find, add, price, pay, complete, download the invoice, start again. */
export function PosPage() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const { can } = useSession();
  const config = usePosConfig();
  const cfg = config.data;
  const cart = useCart();
  const totals = useCartTotals(cfg);
  const checkout = useCheckout(cfg, totals);
  const [finder, setFinder] = useState<Finder>(EMPTY_FINDER);
  const [filters, setFilters] = useState<GridFilters>({ text: '', onlyInStock: false, mirrorType: '', brandId: '', qualityId: '' });
  const [search, setSearch] = useState('');
  const [dialog, setDialog] = useState<Dialog>(null);
  const [quick, setQuick] = useState<PosCard | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const [replace, setReplace] = useState<Record<string, unknown> | null>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const finderRef = useRef<HTMLDivElement>(null);
  const q = useDebouncedValue(search, 150);
  const walkIn = useQuery({ queryKey: [...SALES_KEYS.customers, 'walkin', cfg?.walk_in_customer_id], queryFn: () => customerApi.show(cfg?.walk_in_customer_id ?? 0), enabled: !!cfg, staleTime: 5 * 60_000 });
  const held = useQuery({ queryKey: SALES_KEYS.held, queryFn: saleApi.held, enabled: !!cfg, staleTime: 30_000 });
  const warehouseId = cfg?.warehouses.find((w) => w.is_default)?.id ?? cfg?.warehouses[0]?.id;
  const shiftOpen = !!cfg?.shift;
  const needsShift = !!cfg?.settings.require_open_shift && !shiftOpen;
  const customer = cart.customer ?? (walkIn.data ? toCartCustomer(walkIn.data) : null);

  const add = useCallback((card: Pick<PosCard, 'name' | 'image' | 'warranty_months'>, v: PosVariant, scanned = false) => {
    cart.addVariant(card, v);
    if (scanned) { beep(); setFlash(useCart.getState().selected); setTimeout(() => setFlash(null), 800); }
  }, [cart]);

  const scan = useMutation({
    mutationFn: (code: string) => posApi.scan(code, warehouseId),
    onSuccess: (hit) => {
      if (!hit) { setFilters((f) => ({ ...f, text: search.trim() })); return; }
      if (Number(hit.variant.available) <= 0) { toast.warning(t('pos.scanOut', { sku: hit.variant.sku })); return; }
      add(hit.product, hit.variant, true);
      setSearch('');
      setFilters((f) => ({ ...f, text: '' }));
    },
    onError,
  });

  const newSale = useCallback(() => {
    checkout.clearSale();
    useCart.getState().reset(walkIn.data ? toCartCustomer(walkIn.data) : null);
    setDialog(null);
    setSearch('');
    searchRef.current?.focus();
  }, [checkout, walkIn.data]);

  const holdCart = useMutation({
    mutationFn: () => {
      const s = useCart.getState();
      const payload: LoadedCart = { lines: s.lines, customer: s.customer, vehicle: s.vehicle, channel: s.channel, cartDiscountType: s.cartDiscountType, cartDiscountValue: s.cartDiscountValue, delivery: s.delivery, note: s.note, quotationId: s.quotationId };
      return saleApi.hold({ held_name: `${customer?.name ?? t('pos.walkIn')} ${new Date().toLocaleTimeString('en-GB', { timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit' })}`, customer_id: s.customer?.id ?? cfg?.walk_in_customer_id, warehouse_id: warehouseId, payload });
    },
    onSuccess: (h) => { void client.invalidateQueries({ queryKey: SALES_KEYS.held }); toast.success(t('pos.heldDone', { name: h.held_name })); newSale(); },
    onError,
  });
  const quote = useMutation({
    mutationFn: () => {
      const s = useCart.getState();
      return quotationApi.create({ customer_id: s.customer?.id ?? cfg?.walk_in_customer_id, vehicle: s.vehicle.reg_no || s.vehicle.make ? s.vehicle : null, delivery_charge: s.delivery, lines: s.lines.map((l) => ({ variant_id: l.variant.id, qty: l.qty, unit_price: l.unitPrice, discount_type: l.discountType, discount_value: l.discountValue })) });
    },
    onSuccess: (qt) => { toast.success(t('pos.quoteDone', { no: qt.quote_no })); window.open(quotationApi.pdfUrl(qt.id), '_blank', 'noopener'); newSale(); },
    onError,
  });

  const resume = (payload: Record<string, unknown>) => {
    const loaded = payload as unknown as LoadedCart;
    if (useCart.getState().lines.length > 0) setReplace(payload);
    else cart.load(loaded);
  };
  const reprint = async () => {
    if (!checkout.lastSaleId) return void toast.info(t('pos.noLastInvoice'));
    try { printPdf(await saleApi.pdf(checkout.lastSaleId, { reprint: true })); } catch (e) { onError(e); }
  };
  const empty = cart.lines.length === 0;
  useShortcuts({
    search: () => searchRef.current?.focus(),
    finder: () => finderRef.current?.querySelector<HTMLElement>('button[role="combobox"]')?.focus(),
    customer: () => setDialog('customer'), delivery: () => setDialog('delivery'), discount: () => setDialog('discount'),
    pay: () => { if (!empty) setDialog('pay'); }, hold: () => { if (!empty) holdCart.mutate(); }, held: () => setDialog('held'),
    removeSelected: () => { const k = useCart.getState().selected; if (k) cart.remove(k); },
    qty: (d) => { const k = useCart.getState().selected; if (k) cart.step(k, d); },
    reprint: () => void reprint(), newSale,
  }, !!cfg && !checkout.sale);

  if (config.isLoading) return <PageSkeleton />;
  if (config.isError || !cfg) return <ErrorState onRetry={() => void config.refetch()} />;
  if (!cfg.company_ready) return <ErrorState title={t('pos.companyIncompleteTitle')} body={<>{t('pos.companyIncompleteBody', { missing: cfg.company_missing.join(', ') })} <Link className="font-semibold underline" to="/settings/company">{t('pos.openCompany')}</Link></>} />;
  if (needsShift) return <ShiftGate accounts={cfg.accounts} canOpen={can(['shifts.open_close_own', 'shifts.close_any'])} />;

  const approvalError = checkout.error instanceof ApiError && ['PIN_INVALID', 'PIN_LOCKED'].includes(checkout.error.code) ? apiMessage(t, checkout.error) : null;
  return (
    <div className="grid h-[calc(100vh-7rem)] min-h-[34rem] grid-cols-[62fr_38fr] gap-4" data-testid="pos-terminal">
      <section aria-label={t('pos.catalog')} className="flex min-h-0 flex-col gap-3">
        <div ref={finderRef}><VehicleFinder value={finder} onChange={setFinder} driverSide={cfg.settings.driver_side} /></div>
        <PosToolbar search={search} onSearch={(v) => { setSearch(v); }} onScan={(c) => scan.mutate(c)} filters={{ ...filters, text: q }} onFilters={setFilters} searchRef={searchRef} />
        <div className="min-h-0 flex-1 overflow-y-auto pe-1"><ProductGrid finder={finder} filters={{ ...filters, text: q }} warehouseId={warehouseId} onAdd={(c, v) => add(c, v)} onOpen={setQuick} /></div>
      </section>
      <CartPanel config={cfg} totals={totals} heldCount={held.data?.length ?? 0} drawerOpen={dialog === 'pay'} flashKey={flash} busy={checkout.busy || holdCart.isPending || quote.isPending}
        actions={{ onPay: () => setDialog('pay'), onHold: () => holdCart.mutate(), onQuote: () => quote.mutate(), onCustomer: () => setDialog('customer'), onVehicle: () => setDialog('vehicle'), onHeld: () => setDialog('held'), onCartDiscount: () => setDialog('discount'), onDelivery: () => setDialog('delivery') }} />
      <QuickView card={quick} onClose={() => setQuick(null)} onAdd={(v) => { if (quick) add(quick, v); }} />
      <CustomerPicker open={dialog === 'customer'} walkIn={walkIn.data} onClose={() => setDialog(null)} onPick={(c: CartCustomer) => cart.setCustomer(c)} />
      <VehicleDialog open={dialog === 'vehicle'} customerId={customer && !customer.is_walk_in ? customer.id : null} value={cart.vehicle} onClose={() => setDialog(null)} onSave={cart.setVehicle} />
      <HeldBills open={dialog === 'held'} onClose={() => setDialog(null)} onResume={resume} />
      <DiscountDialog open={dialog === 'discount'} title={t('pos.cartDiscount')} type={cart.cartDiscountType} value={cart.cartDiscountValue} onClose={() => setDialog(null)} onApply={cart.setCartDiscount} />
      <AmountDialog open={dialog === 'delivery'} title={t('pos.delivery')} label={t('pos.deliveryAmount')} value={cart.delivery} onClose={() => setDialog(null)} onApply={cart.setDelivery} />
      <PayDrawer open={dialog === 'pay' && !checkout.sale} onClose={() => setDialog(null)} config={cfg} totals={totals} busy={checkout.busy} error={checkout.error} onComplete={checkout.run} />
      <ApprovalDialog open={checkout.approval !== null} reason={checkout.approval?.reason ?? 'PIN_REQUIRED'} error={approvalError} loading={checkout.busy} onCancel={checkout.cancelApproval} onSubmit={checkout.approve} />
      <ConfirmDialog open={replace !== null} onOpenChange={(o) => !o && setReplace(null)} title={t('pos.replaceTitle')} body={t('pos.replaceBody')} confirmLabel={t('pos.replace')} onConfirm={() => { if (replace) cart.load(replace as unknown as LoadedCart); setReplace(null); }} />
      {checkout.sale && <SuccessScreen sale={checkout.sale} config={cfg} onNewSale={newSale} />}
    </div>
  );
}
