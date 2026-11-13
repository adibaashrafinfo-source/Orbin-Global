import type { Customer, PosVariant } from '../types';
import type { CartCustomer } from './cartStore';

/** Size chip text, for example "7×4 · R" (L or R only when the variant is a left or right mirror). */
export const chipLabel = (v: PosVariant): string => [v.size_label ?? v.sku, v.position === 'left' ? 'L' : v.position === 'right' ? 'R' : ''].filter(Boolean).join(' · ');

export const toCartCustomer = (c: Customer): CartCustomer => ({ id: c.id, code: c.code, name: c.name, phone: c.phone_primary, price_tier: c.price_tier, credit_limit: c.credit_limit ?? '0', balance: c.balance ?? '0', is_walk_in: c.is_walk_in });

export interface Finder {
  make: string;
  model: string;
  year: string;
  position: string;
}

export const EMPTY_FINDER: Finder = { make: '', model: '', year: '', position: '' };
