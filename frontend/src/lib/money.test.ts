import { describe, expect, it } from 'vitest';
import { addMoney, formatMoney, fromPaisa, groupLakhCrore, toPaisa } from './money';

describe('money helper (client preview)', () => {
  it('converts decimal strings to paisa and back without float drift', () => {
    expect(toPaisa('0.10')).toBe(10n);
    expect(toPaisa('12500.00')).toBe(1250000n);
    expect(toPaisa('-1.5')).toBe(-150n);
    expect(fromPaisa(1250000n)).toBe('12500.00');
    expect(fromPaisa(-150n)).toBe('-1.50');
    expect(addMoney('0.10', '0.20')).toBe('0.30');
  });

  it('groups with lakh/crore', () => {
    expect(groupLakhCrore('100')).toBe('100');
    expect(groupLakhCrore('1000')).toBe('1,000');
    expect(groupLakhCrore('99999')).toBe('99,999');
    expect(groupLakhCrore('100000')).toBe('1,00,000');
    expect(groupLakhCrore('12345678')).toBe('1,23,45,678');
  });

  it('formats ৳ with symbol, sign and Bangla numerals', () => {
    expect(formatMoney('125000')).toBe('৳ 1,25,000.00');
    expect(formatMoney('-12.5')).toBe('-৳ 12.50');
    expect(formatMoney('1000', { symbol: false })).toBe('1,000.00');
    expect(formatMoney('1000', { numerals: 'beng' })).toBe('৳ ১,০০০.০০');
  });
});
