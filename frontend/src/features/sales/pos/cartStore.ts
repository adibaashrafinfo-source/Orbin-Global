import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { newIdempotencyKey } from '@/lib/api';
import type { DiscountType, PosCard, PosVariant, PriceTier } from '../types';
import { parseScaled, tierPrice } from './pricing';

export interface CartVariant extends PosVariant {
  product: string;
  image: string | null;
  warranty_months: number;
}

export interface CartLine {
  key: string;
  variant: CartVariant;
  qty: string;
  unitPrice: string;
  discountType: DiscountType;
  discountValue: string;
  note: string;
}

export interface CartCustomer {
  id: number;
  code: string;
  name: string;
  phone: string | null;
  price_tier: PriceTier;
  credit_limit: string;
  balance: string;
  is_walk_in: boolean;
}

export interface CartVehicle {
  customer_vehicle_id: number | null;
  reg_no: string;
  make: string;
  model: string;
  year: string;
  color: string;
}

export const EMPTY_VEHICLE: CartVehicle = { customer_vehicle_id: null, reg_no: '', make: '', model: '', year: '', color: '' };

interface CartState {
  lines: CartLine[];
  selected: string | null;
  customer: CartCustomer | null;
  vehicle: CartVehicle;
  channel: string;
  /** FR-706: who is credited with the sale; null means the person at the till. */
  salespersonId: number | null;
  cartDiscountType: DiscountType;
  cartDiscountValue: string;
  delivery: string;
  note: string;
  quotationId: number | null;
  /** One key per cart as it stands: a retry reuses it, any edit starts a new one (so an edited cart is never mistaken for the first). */
  checkoutKey: string | null;
  lastRemoved: CartLine | null;
  addVariant: (card: Pick<PosCard, 'name' | 'image' | 'warranty_months'>, v: PosVariant) => void;
  setQty: (key: string, qty: string) => void;
  step: (key: string, delta: number) => void;
  remove: (key: string) => void;
  undoRemove: () => void;
  setUnitPrice: (key: string, price: string) => void;
  setLineDiscount: (key: string, type: DiscountType, value: string) => void;
  setLineNote: (key: string, note: string) => void;
  select: (key: string | null) => void;
  setCustomer: (c: CartCustomer) => void;
  setVehicle: (v: CartVehicle) => void;
  setChannel: (c: string) => void;
  setSalesperson: (id: number | null) => void;
  setCartDiscount: (type: DiscountType, value: string) => void;
  setDelivery: (d: string) => void;
  setNote: (n: string) => void;
  load: (cart: LoadedCart) => void;
  reset: (walkIn?: CartCustomer | null) => void;
  ensureKey: () => string;
}

export interface LoadedCart {
  lines: CartLine[];
  customer: CartCustomer | null;
  vehicle: CartVehicle;
  channel: string;
  salespersonId?: number | null;
  cartDiscountType: DiscountType;
  cartDiscountValue: string;
  delivery: string;
  note: string;
  quotationId: number | null;
}

const fresh = {
  lines: [] as CartLine[],
  selected: null as string | null,
  vehicle: EMPTY_VEHICLE,
  channel: 'counter',
  salespersonId: null as number | null,
  cartDiscountType: 'none' as DiscountType,
  cartDiscountValue: '0',
  delivery: '0',
  note: '',
  quotationId: null as number | null,
  checkoutKey: null as string | null,
  lastRemoved: null as CartLine | null,
};

const tier = (c: CartCustomer | null): PriceTier => c?.price_tier ?? 'retail';
const isOverridden = (l: CartLine, t: PriceTier) => parseScaled(l.unitPrice, 2) !== parseScaled(tierPrice(l.variant, t), 2);
const keyOf = () => crypto.randomUUID();

/** The POS cart (FR-705). It lives in the browser and survives a reload; held bills are the server-side copy. */
export const useCart = create<CartState>()(
  persist(
    (set, get) => {
      const edit = (patch: Partial<CartState> | ((s: CartState) => Partial<CartState>)) => set((s) => ({ ...(typeof patch === 'function' ? patch(s) : patch), checkoutKey: null }));
      const mapLine = (key: string, fn: (l: CartLine) => CartLine) => edit((s) => ({ lines: s.lines.map((l) => (l.key === key ? fn(l) : l)) }));
      return {
        ...fresh,
        customer: null,
        addVariant: (card, v) =>
          edit((s) => {
            // The same size again is one more of it (FR-704); a bundle or a different size is its own line.
            const same = s.lines.find((l) => l.variant.id === v.id && l.discountType === 'none' && l.note === '');
            if (same) return { lines: s.lines.map((l) => (l === same ? { ...l, qty: String(Number(l.qty) + 1) } : l)), selected: same.key };
            const line: CartLine = { key: keyOf(), variant: { ...v, product: card.name, image: card.image, warranty_months: card.warranty_months }, qty: '1', unitPrice: tierPrice(v, tier(s.customer)), discountType: 'none', discountValue: '0', note: '' };
            return { lines: [...s.lines, line], selected: line.key };
          }),
        setQty: (key, qty) => mapLine(key, (l) => ({ ...l, qty })),
        step: (key, delta) => mapLine(key, (l) => ({ ...l, qty: String(Math.max(1, Number(l.qty) + delta)) })),
        remove: (key) => edit((s) => ({ lines: s.lines.filter((l) => l.key !== key), lastRemoved: s.lines.find((l) => l.key === key) ?? null, selected: s.selected === key ? null : s.selected })),
        undoRemove: () => edit((s) => (s.lastRemoved ? { lines: [...s.lines, s.lastRemoved], lastRemoved: null, selected: s.lastRemoved.key } : {})),
        setUnitPrice: (key, price) => mapLine(key, (l) => ({ ...l, unitPrice: price })),
        setLineDiscount: (key, type, value) => mapLine(key, (l) => ({ ...l, discountType: type, discountValue: value })),
        setLineNote: (key, note) => mapLine(key, (l) => ({ ...l, note })),
        select: (key) => set({ selected: key }),
        setCustomer: (c) =>
          edit((s) => {
            const before = tier(s.customer);
            // A new customer can mean a new price tier: lines still at the old tier price follow, hand-set prices stay (BR-002).
            const lines = s.lines.map((l) => (isOverridden(l, before) ? l : { ...l, unitPrice: tierPrice(l.variant, c.price_tier) }));
            return { customer: c, lines, vehicle: EMPTY_VEHICLE };
          }),
        setVehicle: (vehicle) => edit({ vehicle }),
        setChannel: (channel) => edit({ channel }),
        setSalesperson: (salespersonId) => edit({ salespersonId }),
        setCartDiscount: (type, value) => edit({ cartDiscountType: type, cartDiscountValue: value }),
        setDelivery: (delivery) => edit({ delivery }),
        setNote: (note) => edit({ note }),
        load: (cart) => set({ ...fresh, ...cart, checkoutKey: null }),
        reset: (walkIn) => set({ ...fresh, customer: walkIn ?? null }),
        ensureKey: () => {
          const existing = get().checkoutKey;
          if (existing) return existing;
          const k = newIdempotencyKey();
          set({ checkoutKey: k });
          return k;
        },
      };
    },
    { name: 'orbin.pos.cart.v1', version: 1 },
  ),
);

export const isLineOverridden = (l: CartLine, customer: CartCustomer | null): boolean => isOverridden(l, tier(customer));
