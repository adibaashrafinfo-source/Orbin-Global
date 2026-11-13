import { toPaisa } from '@/lib/money';
import type { PosConfig } from '../types';
import type { CartLine } from './cartStore';
import type { LineResult } from './pricing';
import { parseScaled } from './pricing';

export type LineWarning = 'lowStock' | 'belowFloor';

/** Inline advice per cart line (the server enforces; these just tell the cashier early). */
export function lineWarnings(line: CartLine, config: PosConfig | undefined): LineWarning[] {
  const out: LineWarning[] = [];
  if (!line.variant.is_bundle && parseScaled(line.qty, 3) > parseScaled(line.variant.available, 3)) out.push('lowStock');
  const floor = line.variant.min_price;
  if (floor !== null && toPaisa(line.unitPrice) < toPaisa(floor) && !config?.can.sell_below_floor) out.push('belowFloor');
  return out;
}

/** True when what is given away exceeds the cashier's share of the bill and a manager PIN will be asked for (BR-021). */
export function overDiscountLimit(subtotal: bigint, lineDiscount: bigint, cartDiscount: bigint, config: PosConfig | undefined): boolean {
  if (!config || config.can.discount_over_limit || subtotal <= 0n) return false;
  return (lineDiscount + cartDiscount) * 100n > BigInt(config.settings.cashier_max_discount_percent) * subtotal;
}

export const lineResult = (results: LineResult[], i: number): LineResult | undefined => results[i];
