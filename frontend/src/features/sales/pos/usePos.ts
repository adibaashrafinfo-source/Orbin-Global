import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { posApi, SALES_KEYS, shiftApi } from '../api';
import type { PosConfig } from '../types';
import { useCart } from './cartStore';
import { computePrice, type PriceResult } from './pricing';

/** Settings, accounts, warehouses and permissions the terminal needs; refreshed when the screen is reopened. */
export function usePosConfig() {
  return useQuery({ queryKey: SALES_KEYS.config, queryFn: posApi.config, staleTime: 60_000 });
}

export function useCurrentShift(enabled = true) {
  return useQuery({ queryKey: SALES_KEYS.shift, queryFn: shiftApi.current, enabled, staleTime: 30_000, refetchInterval: 60_000 });
}

/** The cart priced the way the server will price it (preview only: the server decides and refuses a different total). */
export function useCartTotals(config: PosConfig | undefined): PriceResult {
  const lines = useCart((s) => s.lines);
  const cartType = useCart((s) => s.cartDiscountType);
  const cartValue = useCart((s) => s.cartDiscountValue);
  const delivery = useCart((s) => s.delivery);
  return useMemo(
    () =>
      computePrice(
        lines.map((l) => ({ qty: l.qty, unitPrice: l.unitPrice, discountType: l.discountType, discountValue: l.discountValue, taxRate: l.variant.tax_rate })),
        { type: cartType, value: cartValue },
        delivery,
        config?.settings.vat_mode ?? 'exclusive',
        config?.settings.rounding_mode ?? 'none',
      ),
    [lines, cartType, cartValue, delivery, config],
  );
}
