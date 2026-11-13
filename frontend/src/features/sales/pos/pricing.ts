import { fromPaisa, toPaisa } from '@/lib/money';
import type { DiscountType, PriceTier } from '../types';

/**
 * Preview of the invoice arithmetic, with exactly the rules of the server's SalePricing (BR-001, BR-003, BR-010): the same cart gives
 * the same totals, to the paisa. The browser only previews; the server recomputes and refuses a total that differs.
 * Money is bigint paisa and quantity is bigint thousandths, so there are no floats anywhere.
 */
export interface PriceInput {
  qty: string;
  unitPrice: string;
  discountType: DiscountType;
  discountValue: string;
  taxRate: string;
}

export interface LineResult {
  gross: bigint;
  lineDiscount: bigint;
  cartShare: bigint;
  tax: bigint;
  taxable: bigint;
  lineTotal: bigint;
}

export interface PriceResult {
  lines: LineResult[];
  subtotal: bigint;
  lineDiscountTotal: bigint;
  cartDiscount: bigint;
  taxableAmount: bigint;
  taxTotal: bigint;
  delivery: bigint;
  roundOff: bigint;
  grandTotal: bigint;
}

/** "12.5" → 12500n at 3 places (quantity) or 1250n at 2 (money). Anything that is not a plain decimal reads as zero. */
export function parseScaled(value: string, places: number): bigint {
  const m = /^(\d+)(?:\.(\d*))?$/.exec(value.trim());
  if (!m) return 0n;
  const frac = ((m[2] ?? '') + '0'.repeat(places)).slice(0, places);
  return BigInt(m[1] ?? '0') * 10n ** BigInt(places) + BigInt(frac === '' ? '0' : frac);
}

/** Round half up for non-negative values, the same as the server. */
function divRound(num: bigint, den: bigint): bigint {
  return (num * 2n + den) / (2n * den);
}

function discountAmount(type: DiscountType, value: string, base: bigint): bigint {
  const v = parseScaled(value, 2);
  if (type === 'none' || v === 0n) return 0n;
  const amount = type === 'percent' ? divRound(base * v, 10000n) : v;
  if ((type === 'percent' && v > 10000n) || amount > base) return base; // the server refuses; the preview just caps it
  return amount;
}

/** Largest remainder: the parts always add up to the whole, ties to the earlier line. */
export function allocate(total: bigint, weights: bigint[]): bigint[] {
  const sum = weights.reduce((a, b) => a + b, 0n);
  const out = weights.map(() => 0n);
  if (sum <= 0n || total === 0n) return out;
  const rem = weights.map(() => 0n);
  let given = 0n;
  weights.forEach((w, i) => {
    const exact = total * w;
    out[i] = exact / sum;
    rem[i] = exact % sum;
    given += out[i] ?? 0n;
  });
  let left = total - given;
  const order = weights.map((_, i) => i).sort((a, b) => (rem[b] === rem[a] ? a - b : (rem[b] ?? 0n) > (rem[a] ?? 0n) ? 1 : -1));
  for (const i of order) {
    if (left <= 0n) break;
    if ((weights[i] ?? 0n) > 0n) {
      out[i] = (out[i] ?? 0n) + 1n;
      left -= 1n;
    }
  }
  return out;
}

export function computePrice(lines: PriceInput[], cart: { type: DiscountType; value: string }, delivery: string, vatMode: 'inclusive' | 'exclusive', rounding: 'none' | 'nearest_1'): PriceResult {
  const rows = lines.map((l) => {
    const qty = parseScaled(l.qty, 3);
    const price = parseScaled(l.unitPrice, 2);
    const gross = divRound(qty * price, 1000n);
    const lineDiscount = discountAmount(l.discountType, l.discountValue, gross);
    return { gross, lineDiscount, net: gross - lineDiscount, rate: parseScaled(l.taxRate, 2) };
  });
  const netSum = rows.reduce((a, r) => a + r.net, 0n);
  const cartDiscount = discountAmount(cart.type, cart.value, netSum);
  const shares = allocate(cartDiscount, rows.map((r) => r.net));
  const inclusive = vatMode === 'inclusive';
  let taxableTotal = 0n;
  let taxTotal = 0n;
  let payable = 0n;
  const results = rows.map((r, i): LineResult => {
    const share = shares[i] ?? 0n;
    const base = r.net - share;
    const tax = inclusive ? divRound(base * r.rate, 10000n + r.rate) : divRound(base * r.rate, 10000n);
    const taxable = inclusive ? base - tax : base;
    const lineTotal = inclusive ? base : base + tax;
    taxableTotal += taxable;
    taxTotal += tax;
    payable += lineTotal;
    return { gross: r.gross, lineDiscount: r.lineDiscount, cartShare: share, tax, taxable, lineTotal };
  });
  const deliveryPaisa = parseScaled(delivery, 2);
  const before = payable + deliveryPaisa;
  const roundOff = rounding === 'nearest_1' ? divRound(before, 100n) * 100n - before : 0n;
  return {
    lines: results,
    subtotal: rows.reduce((a, r) => a + r.gross, 0n),
    lineDiscountTotal: rows.reduce((a, r) => a + r.lineDiscount, 0n),
    cartDiscount,
    taxableAmount: taxableTotal,
    taxTotal,
    delivery: deliveryPaisa,
    roundOff,
    grandTotal: before + roundOff,
  };
}

/** The customer's tier price; a tier with no price falls back to retail (BR-002). */
export function tierPrice(v: { retail_price: string; wholesale_price: string | null; dealer_price: string | null }, tier: PriceTier): string {
  const p = tier === 'wholesale' ? v.wholesale_price : tier === 'dealer' ? v.dealer_price : v.retail_price;
  return p ?? v.retail_price;
}

export const money = (paisa: bigint): string => fromPaisa(paisa);
export { toPaisa };
