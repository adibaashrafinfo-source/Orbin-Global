import type { Account, Method } from './types';

export const METHODS: Method[] = ['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer'];
export const TYPE_OF: Record<Method, Account['account_type']> = { cash: 'cash', bkash: 'bkash', nagad: 'nagad', rocket: 'rocket', bank_transfer: 'bank' };

export interface Refs {
  sender_number: string;
  trx_id: string;
  bank_name: string;
  bank_ref: string;
}

export const EMPTY_REFS: Refs = { sender_number: '', trx_id: '', bank_name: '', bank_ref: '' };

/** Whether the references a method needs are filled in (BR-060): mobile money wants a sender number and a TrxID, a bank wants its name and a reference. */
export function refsComplete(method: Method, r: Refs): boolean {
  if (method === 'cash') return true;
  if (method === 'bank_transfer') return r.bank_name.trim() !== '' && r.bank_ref.trim() !== '';
  return /^01[3-9]\d{8}$/.test(r.sender_number) && r.trx_id.trim() !== '';
}
