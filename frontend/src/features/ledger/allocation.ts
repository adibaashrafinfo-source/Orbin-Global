import { toPaisa } from '@/lib/money';
import type { OpenDoc } from './types';

export interface Preview {
  /** Paisa applied to each document, keyed "table:id". */
  applied: Map<string, bigint>;
  allocated: bigint;
  advance: bigint;
}

export const docKey = (d: Pick<OpenDoc, 'table' | 'id'>) => `${d.table}:${d.id}`;

/** BR-062 preview only (the server decides): oldest document first, whatever is left over becomes an advance. */
export function previewOldestFirst(docs: OpenDoc[], amount: string): Preview {
  let left = amount === '' ? 0n : toPaisa(amount);
  const applied = new Map<string, bigint>();
  for (const d of docs) {
    if (left <= 0n) break;
    const open = toPaisa(d.open);
    const take = left < open ? left : open;
    if (take > 0n) {
      applied.set(docKey(d), take);
      left -= take;
    }
  }
  const total = amount === '' ? 0n : toPaisa(amount);
  return { applied, allocated: total - left, advance: left };
}

/** The user's own split: what they typed per document, and how much of the payment that leaves as an advance. */
export function previewManual(docs: OpenDoc[], typed: Record<string, string>, amount: string): Preview & { overDue: string[]; overAmount: boolean } {
  const applied = new Map<string, bigint>();
  const overDue: string[] = [];
  let sum = 0n;
  for (const d of docs) {
    const v = typed[docKey(d)];
    if (!v) continue;
    const p = toPaisa(v);
    if (p <= 0n) continue;
    applied.set(docKey(d), p);
    sum += p;
    if (p > toPaisa(d.open)) overDue.push(docKey(d));
  }
  const total = amount === '' ? 0n : toPaisa(amount);
  return { applied, allocated: sum, advance: total - sum, overDue, overAmount: sum > total };
}

/** Text for the "send reminder" copy button (FR-1007): short, polite, with the amount and the shop's name. */
export function reminderText(opts: { name: string; amount: string; company: string; phone?: string | null; template: string }): string {
  return opts.template.replace('{{name}}', opts.name).replace('{{amount}}', opts.amount).replace('{{company}}', opts.company).replace('{{phone}}', opts.phone ?? '').trim();
}
