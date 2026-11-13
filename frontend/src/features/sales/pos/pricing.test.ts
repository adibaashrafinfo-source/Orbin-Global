import { describe, expect, it } from 'vitest';
import { allocate, computePrice, parseScaled, tierPrice, type PriceInput } from './pricing';

const line = (qty: string, unitPrice: string, discountType: PriceInput['discountType'] = 'none', discountValue = '0', taxRate = '0'): PriceInput => ({ qty, unitPrice, discountType, discountValue, taxRate });
const none = { type: 'none' as const, value: '0' };

// The same worked examples as backend/tests/Unit/SalePricingTest.php, so browser and server cannot drift apart.
describe('computePrice mirrors the server', () => {
  it('adds a plain cart', () => {
    const r = computePrice([line('2', '12500.00'), line('1', '3000.00')], none, '0', 'exclusive', 'none');
    expect(r.grandTotal).toBe(2800000n);
    expect(r.taxTotal).toBe(0n);
  });

  it('applies the line percent first, then spreads the cart discount over what is left', () => {
    const r = computePrice([line('2', '1000', 'percent', '10'), line('1', '600')], { type: 'amount', value: '240' }, '0', 'exclusive', 'none');
    expect(r.lines.map((l) => l.cartShare)).toEqual([18000n, 6000n]);
    expect(r.lines.map((l) => l.lineTotal)).toEqual([162000n, 54000n]);
    expect(r.grandTotal).toBe(216000n);
    expect([r.lineDiscountTotal, r.cartDiscount]).toEqual([20000n, 24000n]);
  });

  it('shares of a cart discount always add up to the discount', () => {
    const r = computePrice([line('1', '33.33'), line('1', '33.33'), line('1', '33.33')], { type: 'amount', value: '10.00' }, '0', 'exclusive', 'none');
    expect(r.lines.reduce((a, l) => a + l.cartShare, 0n)).toBe(1000n);
    expect(r.grandTotal).toBe(9999n - 1000n);
  });

  it('adds VAT on the taxable amount when exclusive', () => {
    const r = computePrice([line('1', '1000', 'amount', '100', '15')], none, '0', 'exclusive', 'none');
    expect([r.taxTotal, r.taxableAmount, r.grandTotal]).toEqual([13500n, 90000n, 103500n]);
  });

  it('takes VAT out of the price when inclusive', () => {
    const r = computePrice([line('1', '1150', 'none', '0', '15')], none, '0', 'inclusive', 'none');
    expect([r.taxTotal, r.taxableAmount, r.grandTotal]).toEqual([15000n, 100000n, 115000n]);
  });

  it('rounds to the nearest taka as its own figure', () => {
    expect(computePrice([line('1', '100.49')], none, '0', 'exclusive', 'nearest_1')).toMatchObject({ roundOff: -49n, grandTotal: 10000n });
    expect(computePrice([line('1', '100.50')], none, '0', 'exclusive', 'nearest_1')).toMatchObject({ roundOff: 50n, grandTotal: 10100n });
    expect(computePrice([line('1', '99.60')], none, '50.40', 'exclusive', 'nearest_1')).toMatchObject({ roundOff: 0n, grandTotal: 15000n });
  });

  it('rounds a fractional quantity line half up', () => {
    expect(computePrice([line('0.5', '12.35')], none, '0', 'exclusive', 'none').grandTotal).toBe(618n);
  });
});

describe('helpers', () => {
  it('parses quantities and money without floats', () => {
    expect(parseScaled('12.5', 3)).toBe(12500n);
    expect(parseScaled('7', 2)).toBe(700n);
    expect(parseScaled('0.075', 3)).toBe(75n);
    expect(parseScaled('abc', 2)).toBe(0n);
  });

  it('allocates by largest remainder with ties to the earlier index', () => {
    expect(allocate(100n, [1n, 1n, 1n])).toEqual([34n, 33n, 33n]);
    expect(allocate(5n, [0n, 3n, 3n])).toEqual([0n, 3n, 2n]);
    expect(allocate(0n, [1n, 2n])).toEqual([0n, 0n]);
  });

  it('uses the tier price and falls back to retail', () => {
    const v = { retail_price: '1000.00', wholesale_price: '800.00', dealer_price: null };
    expect([tierPrice(v, 'retail'), tierPrice(v, 'wholesale'), tierPrice(v, 'dealer')]).toEqual(['1000.00', '800.00', '1000.00']);
  });
});
