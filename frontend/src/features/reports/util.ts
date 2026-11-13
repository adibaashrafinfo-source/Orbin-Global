import { formatMoney } from '@/lib/money';
import type { Filters, RangeKey, ReportColumn } from './types';

const fmt = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(d);
const addDays = (iso: string, n: number) => fmt(new Date(Date.parse(`${iso}T12:00:00Z`) + n * 86_400_000));

export const todayIso = (): string => fmt(new Date());

/** The dates a quick range means, by the Dhaka calendar. Weeks start on Saturday (BR-090). */
export function rangeDates(range: Exclude<RangeKey, 'custom'>, today = todayIso()): { from: string; to: string } {
  if (range === 'today') return { from: today, to: today };
  if (range === 'week') {
    const dow = new Date(`${today}T12:00:00Z`).getUTCDay(); // 0 = Sunday
    return { from: addDays(today, -((dow + 1) % 7)), to: today };
  }
  if (range === 'month') return { from: `${today.slice(0, 8)}01`, to: today };
  const first = `${today.slice(0, 8)}01`;
  const to = addDays(first, -1);

  return { from: `${to.slice(0, 8)}01`, to };
}

export const defaultFilters = (): Filters => ({ range: 'month', ...rangeDates('month'), asOf: todayIso(), branchId: '', warehouseId: '', group: '', variantId: '', compare: false, sort: '', dir: 'asc', page: 1 });

/** A column label as an i18n key: "Revenue (ex-VAT)" → "revenue_ex_vat". */
export const labelKey = (label: string): string => label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

/** Where a document in a report row opens. */
export function docRoute(table: string, id: number): string | null {
  switch (table) {
    case 'sales': return `/sales/${id}`;
    case 'sale_returns': return `/sales/returns/${id}`;
    case 'goods_receipts': return `/purchases/receipts/${id}`;
    case 'purchase_orders': return `/purchases/orders/${id}`;
    case 'stock_adjustments': return `/inventory/adjustments/${id}`;
    case 'stock_counts': return `/inventory/counts/${id}`;
    case 'customers': return `/customers/${id}`;
    case 'suppliers': return `/purchases/suppliers`;
    case 'cash_shifts': return `/shifts/${id}`;
    case 'variants': return `/inventory/card/${id}`;
    default: return null;
  }
}

/** A cell value as plain text: strings and numbers only, anything else is blank. */
export const plain = (v: unknown): string => (typeof v === 'string' || typeof v === 'number' ? String(v) : '');

/** One cell as text. Money gets lakh/crore grouping; costs keep their four decimals; quantities drop trailing zeros. */
export function cellText(c: ReportColumn, v: unknown): string {
  const s = plain(v);
  if (s === '') return '';
  switch (c.type) {
    case 'money': return formatMoney(s, { symbol: false });
    case 'qty': return String(Number(s));
    case 'percent': return `${s}%`;
    default: return s;
  }
}
