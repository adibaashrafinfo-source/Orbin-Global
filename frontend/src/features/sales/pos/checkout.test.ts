import { beforeEach, describe, expect, it } from 'vitest';
import type { PriceResult } from './pricing';
import { useCart } from './cartStore';
import { buildCheckoutBody } from './checkout';
import type { PosConfig } from '../types';

const config = { walk_in_customer_id: 1, warehouses: [{ id: 7, name: 'Showroom', is_default: true }], settings: {} } as unknown as PosConfig;
const totals = { grandTotal: 100000n, lines: [] } as unknown as PriceResult;

describe('buildCheckoutBody: sale details (FR-706)', () => {
  beforeEach(() => useCart.getState().reset());

  it('sends the channel and the note, and leaves the salesperson out when none is picked', () => {
    useCart.getState().setChannel('phone');
    useCart.getState().setNote('Deliver after 6');
    const body = buildCheckoutBody(config, totals, []);
    expect(body.sale_channel).toBe('phone');
    expect(body.note).toBe('Deliver after 6');
    expect(body).not.toHaveProperty('salesperson_id');
  });

  it('sends the chosen salesperson', () => {
    useCart.getState().setSalesperson(42);
    expect(buildCheckoutBody(config, totals, []).salesperson_id).toBe(42);
  });
});
