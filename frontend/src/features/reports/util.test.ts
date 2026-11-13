import { describe, expect, it } from 'vitest';
import { docRoute, labelKey, rangeDates } from './util';

describe('rangeDates', () => {
  it('knows today and this month', () => {
    expect(rangeDates('today', '2026-11-13')).toEqual({ from: '2026-11-13', to: '2026-11-13' });
    expect(rangeDates('month', '2026-11-13')).toEqual({ from: '2026-11-01', to: '2026-11-13' });
  });
  it('starts the week on Saturday', () => {
    // 2026-11-13 is a Friday: the week began on Saturday the 7th.
    expect(rangeDates('week', '2026-11-13')).toEqual({ from: '2026-11-07', to: '2026-11-13' });
    // On a Saturday the week is just that day.
    expect(rangeDates('week', '2026-11-14')).toEqual({ from: '2026-11-14', to: '2026-11-14' });
    // On a Sunday it is two days.
    expect(rangeDates('week', '2026-11-15')).toEqual({ from: '2026-11-14', to: '2026-11-15' });
  });
  it('gives the whole of last month, across a year end too', () => {
    expect(rangeDates('last_month', '2026-11-13')).toEqual({ from: '2026-10-01', to: '2026-10-31' });
    expect(rangeDates('last_month', '2026-01-05')).toEqual({ from: '2025-12-01', to: '2025-12-31' });
  });
});

describe('labelKey', () => {
  it('turns a label into a stable key', () => {
    expect(labelKey('Revenue (ex-VAT)')).toBe('revenue_ex_vat');
    expect(labelKey('Margin %')).toBe('margin');
    expect(labelKey('180+ days')).toBe('180_days');
    expect(labelKey('0–15 days')).toBe('0_15_days');
  });
});

describe('docRoute', () => {
  it('maps a document to its screen', () => {
    expect(docRoute('sales', 7)).toBe('/sales/7');
    expect(docRoute('goods_receipts', 3)).toBe('/purchases/receipts/3');
    expect(docRoute('nothing', 1)).toBeNull();
  });
});
