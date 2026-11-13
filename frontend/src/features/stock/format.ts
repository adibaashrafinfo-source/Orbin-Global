/** "12.500" → "12.5", "3.000" → "3". Quantities are stored with 3 places but read better trimmed. */
export function fmtQty(v: string | number | null | undefined): string {
  if (v === null || v === undefined || v === '') return '—';
  const s = String(v);
  return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
}

/** Signed movement quantity with an explicit plus. */
export function fmtDelta(v: string): string {
  const q = fmtQty(v);
  return q.startsWith('-') ? q : `+${q}`;
}

/** Taka with the symbol, lakh grouping, and the cost columns' four places trimmed to two unless they matter. */
export function trimCost(v: string | null | undefined): string {
  if (!v) return '—';
  return Number(v).toFixed(4).replace(/(\.\d\d)00$/, '$1');
}

/** Today in the shop's timezone as YYYY-MM-DD (BR-090). */
export function todayDhaka(): string {
  return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Dhaka' });
}

/** qty (3 places) × unit cost (2 places) in paisa, rounded half up, as an exact BigInt so the preview matches the server. */
export function lineTotalPaisa(qty: string, cost: string): bigint | null {
  const q = /^\d+(\.\d{1,3})?$/.exec(qty.trim());
  const c = /^\d+(\.\d{1,2})?$/.exec(cost.trim());
  if (!q || !c) return null;
  const milli = BigInt(Math.round(Number(qty) * 1000));
  const paisa = BigInt(Math.round(Number(cost) * 100));
  const product = milli * paisa;
  return (product + 500n) / 1000n;
}

export function paisaToMoney(p: bigint): string {
  const neg = p < 0n;
  const abs = neg ? -p : p;
  return `${neg ? '-' : ''}${abs / 100n}.${String(abs % 100n).padStart(2, '0')}`;
}
