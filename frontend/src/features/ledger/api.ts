import { http, newIdempotencyKey, type Envelope } from '@/lib/api';
import type { Account, AccountTxn, DayBook, Dues, Expense, ExpenseCategory, FundTransfer, OpenDoc, PartyPayment, PartyType, PaymentBody, Statement } from './types';

export interface Page<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

type Params = Record<string, unknown>;
const clean = (p: Params): Params => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== undefined && v !== null && v !== false));

export const LEDGER_KEYS = {
  accounts: ['finance', 'accounts'] as const,
  payments: ['finance', 'payments'] as const,
  statement: ['finance', 'statement'] as const,
  openDocs: ['finance', 'open-docs'] as const,
  expenses: ['finance', 'expenses'] as const,
  categories: ['finance', 'expense-categories'] as const,
  transfers: ['finance', 'transfers'] as const,
  dayBook: ['finance', 'day-book'] as const,
  dues: ['finance', 'dues'] as const,
  lock: ['finance', 'period-lock'] as const,
};

/** Everything a money movement changes: balances, dues, ledgers, invoices, customers and suppliers. */
export const MONEY_KEYS = [['finance'], ['customers'], ['sales'], ['suppliers'], ['shifts'], ['receipts'], ['purchases']] as const;

export const accountApi = {
  list: () => http.get<Envelope<Account[]>>('/financial-accounts').then((r) => r.data.data),
  create: (body: Params) => http.post<Envelope<Account>>('/financial-accounts', body).then((r) => r.data.data),
  update: (id: number, body: Params) => http.put<Envelope<Account>>(`/financial-accounts/${id}`, body).then((r) => r.data.data),
  remove: (id: number) => http.delete(`/financial-accounts/${id}`).then(() => undefined),
  adjust: (id: number, body: { direction: 'in' | 'out'; amount: string; reason: string; date?: string }) => http.post<Envelope<Account>>(`/financial-accounts/${id}/adjust`, clean(body)).then((r) => r.data.data),
  transactions: (id: number, p: Params) => http.get<Page<AccountTxn>>(`/financial-accounts/${id}/transactions`, { params: clean(p) }).then((r) => r.data),
};

export const paymentApi = {
  list: (p: Params) => http.get<Page<PartyPayment>>('/party-payments', { params: clean(p) }).then((r) => r.data),
  openDocuments: (type: PartyType, id: number) => http.get<Envelope<{ balance: string; documents: OpenDoc[] }>>('/party-payments/open-documents', { params: { party_type: type, party_id: id } }).then((r) => r.data.data),
  /** One key per attempt: a retry after a network drop reuses it, so the server returns the first payment instead of a second. */
  record: (body: PaymentBody, key: string) => http.post<Envelope<PartyPayment>>('/party-payments', body, { headers: { 'Idempotency-Key': key } }).then((r) => r.data.data),
  void: (id: number, reason: string) => http.post<Envelope<PartyPayment>>(`/party-payments/${id}/void`, { reason }).then((r) => r.data.data),
  confirm: (id: number) => http.post<Envelope<PartyPayment>>(`/party-payments/${id}/confirm`).then((r) => r.data.data),
  receipt: (id: number) => http.get<Blob>(`/party-payments/${id}/receipt`, { responseType: 'blob' }).then((r) => r.data),
};

export const ledgerApi = {
  statement: (type: PartyType, id: number, from?: string, to?: string) => http.get<Envelope<Statement>>('/party-ledger', { params: clean({ party_type: type, party_id: id, from, to }) }).then((r) => r.data.data),
  statementPdf: (type: PartyType, id: number, from?: string, to?: string) => http.get<Blob>('/party-ledger/statement.pdf', { params: clean({ party_type: type, party_id: id, from, to }), responseType: 'blob' }).then((r) => r.data),
  writeOff: (body: { party_type: PartyType; party_id: number; amount: string; kind: 'writeoff' | 'discount'; reason: string; date?: string }) => http.post<Envelope<{ balance: string }>>('/party-ledger/write-offs', clean(body)).then((r) => r.data.data),
  adjust: (body: { party_type: PartyType; party_id: number; direction: 'increase' | 'decrease'; amount: string; reason: string; date?: string }) => http.post<Envelope<{ balance: string }>>('/party-ledger/adjustments', clean(body)).then((r) => r.data.data),
};

export const expenseApi = {
  list: (p: Params) => http.get<Page<Expense>>('/expenses', { params: clean(p) }).then((r) => r.data),
  create: (body: Params) => http.post<Envelope<Expense>>('/expenses', clean(body)).then((r) => r.data.data),
  approve: (id: number) => http.post<Envelope<Expense>>(`/expenses/${id}/approve`).then((r) => r.data.data),
  reject: (id: number, reason: string) => http.post<Envelope<Expense>>(`/expenses/${id}/reject`, { reason }).then((r) => r.data.data),
  void: (id: number, reason: string) => http.post<Envelope<Expense>>(`/expenses/${id}/void`, { reason }).then((r) => r.data.data),
};

export const categoryApi = {
  list: () => http.get<Envelope<ExpenseCategory[]>>('/expense-categories').then((r) => r.data.data),
  create: (body: Params) => http.post<Envelope<ExpenseCategory>>('/expense-categories', body).then((r) => r.data.data),
  update: (id: number, body: Params) => http.put<Envelope<ExpenseCategory>>(`/expense-categories/${id}`, body).then((r) => r.data.data),
  remove: (id: number) => http.delete(`/expense-categories/${id}`).then(() => undefined),
};

export const transferApi = {
  list: (p: Params) => http.get<Page<FundTransfer>>('/fund-transfers', { params: clean(p) }).then((r) => r.data),
  create: (body: Params) => http.post<Envelope<FundTransfer>>('/fund-transfers', clean(body)).then((r) => r.data.data),
  void: (id: number, reason: string) => http.post<Envelope<FundTransfer>>(`/fund-transfers/${id}/void`, { reason }).then((r) => r.data.data),
};

export const dayBookApi = { get: (p: Params) => http.get<DayBook>('/day-book', { params: clean(p) }).then((r) => r.data) };

export const duesApi = {
  list: (p: Params) => http.get<Dues>('/dues', { params: clean(p) }).then((r) => r.data),
  promise: (body: { party_type: PartyType; party_id: number; promise_date: string | null; promise_note?: string }) => http.put<Envelope<unknown>>('/dues/promise', body).then((r) => r.data.data),
};

export const lockApi = {
  get: () => http.get<Envelope<{ lock_date: string | null }>>('/settings/period-lock').then((r) => r.data.data),
  set: (lock_date: string | null) => http.post<Envelope<{ lock_date: string | null }>>('/settings/period-lock', { lock_date }).then((r) => r.data.data),
};

export { newIdempotencyKey };
