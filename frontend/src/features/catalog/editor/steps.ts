import type { Product } from '../types';

export const STEPS = ['basic', 'variants', 'images', 'fitment', 'pricing', 'stock', 'custom', 'review'] as const;
export type StepKey = (typeof STEPS)[number];

export function isStep(v: string | null): v is StepKey {
  return v !== null && (STEPS as readonly string[]).includes(v);
}

/** What every editor step receives. `canPrice` gates price columns, `canCost` the cost column (absent from the API otherwise). */
export interface StepProps {
  product: Product;
  canManage: boolean;
  canPrice: boolean;
  canCost: boolean;
}
