/**
 * Decimal-safe money preview helper (client side only previews; the server decides).
 * Money is carried as decimal strings ("12500.00") and handled in integer paisa.
 */
export type Money = string;

export function toPaisa(value: Money | number): bigint {
  const s = typeof value === 'number' ? value.toFixed(2) : value.trim();
  const neg = s.startsWith('-');
  const [intPart = '0', fracPart = ''] = s.replace('-', '').split('.');
  const frac = (fracPart + '00').slice(0, 2);
  const paisa = BigInt(intPart || '0') * 100n + BigInt(frac || '0');
  return neg ? -paisa : paisa;
}

export function fromPaisa(paisa: bigint): Money {
  const neg = paisa < 0n;
  const abs = neg ? -paisa : paisa;
  const int = abs / 100n;
  const frac = (abs % 100n).toString().padStart(2, '0');
  return `${neg ? '-' : ''}${int.toString()}.${frac}`;
}

export function addMoney(...values: Money[]): Money {
  return fromPaisa(values.reduce((acc, v) => acc + toPaisa(v), 0n));
}

/** Lakh/crore grouping: 1,25,000.00 */
export function groupLakhCrore(intPart: string): string {
  if (intPart.length <= 3) return intPart;
  const last3 = intPart.slice(-3);
  let rest = intPart.slice(0, -3);
  const groups: string[] = [];
  while (rest.length > 2) {
    groups.unshift(rest.slice(-2));
    rest = rest.slice(0, -2);
  }
  if (rest) groups.unshift(rest);
  return `${groups.join(',')},${last3}`;
}

const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'] as const;

export interface FormatMoneyOptions {
  symbol?: boolean;
  numerals?: 'latn' | 'beng';
}

/** Formats "125000.00" → "৳ 1,25,000.00". Negative → "-৳ 1,25,000.00". */
export function formatMoney(value: Money | number, opts: FormatMoneyOptions = {}): string {
  const { symbol = true, numerals = 'latn' } = opts;
  const paisa = toPaisa(value);
  const neg = paisa < 0n;
  const abs = fromPaisa(neg ? -paisa : paisa);
  const [intPart = '0', frac = '00'] = abs.split('.');
  let text = `${groupLakhCrore(intPart)}.${frac}`;
  if (numerals === 'beng') text = text.replace(/\d/g, (d) => BN_DIGITS[Number(d)] ?? d);
  return `${neg ? '-' : ''}${symbol ? '৳ ' : ''}${text}`;
}
