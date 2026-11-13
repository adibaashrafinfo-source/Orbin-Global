import { fromPaisa } from '@/lib/money';
import { parseScaled } from './pricing';
import type { PayLine } from './checkout';

export const fromMoney = (paisa: bigint): string => fromPaisa(paisa);
export const paisa = (s: string): bigint => parseScaled(s, 2);

export interface PayTotals {
  paid: bigint;
  remaining: bigint;
  change: bigint;
}

/** Remaining to pay and the cash change, from the lines entered so far (the server re-checks all of it). */
export function payTotals(grand: bigint, lines: PayLine[]): PayTotals {
  const paid = lines.reduce((a, l) => a + paisa(l.amount), 0n);
  const change = lines.reduce((a, l) => (l.method === 'cash' && l.tendered !== '' && paisa(l.tendered) > paisa(l.amount) ? a + paisa(l.tendered) - paisa(l.amount) : a), 0n);
  return { paid, remaining: grand > paid ? grand - paid : 0n, change };
}

export const BD_MOBILE = /^01[3-9]\d{8}$/;

/** What is missing before a line can be added, as i18n keys of the form pos.pay.err.*. */
export function lineProblem(l: PayLine): string | null {
  if (paisa(l.amount) <= 0n) return 'amount';
  if (l.method === 'cash' && l.tendered !== '' && paisa(l.tendered) < paisa(l.amount)) return 'tendered';
  if (l.method === 'bkash' || l.method === 'nagad' || l.method === 'rocket') {
    if (!BD_MOBILE.test(l.sender_number.replace(/\D/g, ''))) return 'sender';
    if (l.trx_id.trim() === '') return 'trx';
  }
  if (l.method === 'bank_transfer' && (l.bank_name.trim() === '' || l.bank_ref.trim() === '')) return 'bank';
  return null;
}

export const blankLine = (method: PayLine['method'], amount: string, accountId: string): PayLine => ({ id: crypto.randomUUID(), method, amount, tendered: '', account_id: accountId, sender_number: '', trx_id: '', bank_name: '', bank_ref: '', transfer_date: '' });
