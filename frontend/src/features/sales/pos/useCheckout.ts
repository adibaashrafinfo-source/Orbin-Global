import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { ApiError } from '@/lib/api';
import { saleApi, SALES_KEYS } from '../api';
import type { PosConfig, Sale } from '../types';
import { useCart } from './cartStore';
import { buildCheckoutBody, type PayLine } from './checkout';
import type { PriceResult } from './pricing';

export type ApprovalReason = 'PIN_REQUIRED' | 'DUPLICATE_TRXID' | 'PIN_INVALID' | 'PIN_LOCKED';
const APPROVAL: string[] = ['PIN_REQUIRED', 'DUPLICATE_TRXID', 'PIN_INVALID', 'PIN_LOCKED'];

/**
 * FR-711 from the browser's side: one idempotent call. A PIN, duplicate-TrxID or credit question opens the manager approval and the
 * same request is sent again with it; a dropped connection leaves the cart as it was and the key unchanged, so Retry cannot double-sell.
 */
export function useCheckout(config: PosConfig | undefined, totals: PriceResult) {
  const client = useQueryClient();
  const [sale, setSale] = useState<Sale | null>(null);
  const [lastSaleId, setLastSaleId] = useState<number | null>(null);
  const [approval, setApproval] = useState<{ reason: ApprovalReason; lines: PayLine[] } | null>(null);
  const mutation = useMutation({
    mutationFn: ({ lines, approvals }: { lines: PayLine[]; approvals?: { manager_pin: string; reason: string } }) => {
      if (!config) throw new Error('POS configuration is not loaded');
      return saleApi.checkout(buildCheckoutBody(config, totals, lines, approvals), useCart.getState().ensureKey());
    },
    onSuccess: (s) => {
      setApproval(null);
      setSale(s);
      setLastSaleId(s.id);
      for (const key of [SALES_KEYS.shift, SALES_KEYS.sales, ['pos', 'variants'], ['inventory'], SALES_KEYS.customers]) void client.invalidateQueries({ queryKey: key });
    },
    onError: (e, vars) => {
      if (e instanceof ApiError && APPROVAL.includes(e.code)) setApproval({ reason: e.code as ApprovalReason, lines: vars.lines });
      if (e instanceof ApiError && e.code === 'TOTAL_MISMATCH') void client.invalidateQueries({ queryKey: SALES_KEYS.config });
    },
  });
  return {
    sale,
    lastSaleId,
    approval,
    busy: mutation.isPending,
    error: mutation.error,
    run: (lines: PayLine[]) => mutation.mutate({ lines }),
    approve: (pin: string, reason: string) => approval && mutation.mutate({ lines: approval.lines, approvals: { manager_pin: pin, reason } }),
    cancelApproval: () => { setApproval(null); mutation.reset(); },
    clearSale: () => { setSale(null); mutation.reset(); },
  };
}
