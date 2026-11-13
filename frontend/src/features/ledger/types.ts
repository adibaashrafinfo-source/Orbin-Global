export type PartyType = 'customer' | 'supplier';
export type Method = 'cash' | 'bkash' | 'nagad' | 'rocket' | 'bank_transfer';
export type AccountType = 'cash' | 'bkash' | 'nagad' | 'rocket' | 'bank';

export interface Account {
  id: number;
  code: string;
  name: string;
  account_type: AccountType;
  provider: string | null;
  is_default_for_method: boolean;
  is_active: boolean;
  /** Only present for people who may see ledgers or manage accounts. */
  account_number?: string | null;
  bank_name?: string | null;
  bank_branch?: string | null;
  opening_balance?: string;
  opening_date?: string;
  note?: string | null;
  balance?: string;
}

export interface AccountTxn {
  id: number;
  date: string;
  direction: 'in' | 'out';
  amount: string;
  ref_type: string;
  ref_id: number | null;
  ref_no: string | null;
  description: string | null;
  party_type: PartyType | null;
  party_id: number | null;
  balance: string;
}

export interface OpenDoc {
  table: 'sales' | 'goods_receipts' | 'party_ledger_entries';
  id: number;
  no: string;
  date: string;
  total: string;
  open: string;
}

export interface PartyPayment {
  id: number;
  payment_no: string;
  party_type: PartyType;
  party_id: number;
  direction: 'receive' | 'pay';
  method: Method;
  amount: string;
  account_id: number;
  sender_number: string | null;
  trx_id: string | null;
  bank_name: string | null;
  bank_ref: string | null;
  payment_date: string;
  allocated_total: string;
  advance_amount: string;
  verification: 'confirmed' | 'pending' | 'rejected';
  status: 'posted' | 'void';
  void_reason: string | null;
  note: string | null;
  has_receipt: boolean;
  created_by: number | null;
  party: { id: number; code: string; name: string } | null;
  account?: { id: number; name: string; account_type: AccountType };
  allocations?: { doc_table: string; doc_id: number; doc_no: string | null; amount: string }[];
}

export interface PaymentBody {
  party_type: PartyType;
  party_id: number;
  method: Method;
  amount: string;
  account_id?: number;
  sender_number?: string;
  trx_id?: string;
  bank_name?: string;
  bank_ref?: string;
  payment_date?: string;
  auto_oldest_first: boolean;
  allocations?: { doc_table: string; doc_id: number; amount: string }[];
  note?: string;
  approvals?: { manager_pin: string; reason?: string };
}

export interface StatementRow {
  id: number;
  date: string;
  type: string;
  ref_table: string | null;
  ref_id: number | null;
  ref_no: string | null;
  description: string | null;
  debit: string;
  credit: string;
  balance: string;
}

export interface Statement {
  party: { type: PartyType; id: number; code: string; name: string; balance: string };
  opening: string;
  closing: string;
  total_debit: string;
  total_credit: string;
  rows: StatementRow[];
}

export interface ExpenseCategory {
  id: number;
  parent_id: number | null;
  name_en: string;
  name_bn: string | null;
  is_active: boolean;
  is_system: boolean;
}

export interface Expense {
  id: number;
  expense_no: string;
  category_id: number;
  amount: string;
  account_id: number;
  expense_date: string;
  payee: string | null;
  note: string | null;
  status: 'pending_approval' | 'posted' | 'rejected' | 'void';
  decision_note: string | null;
  created_by: number | null;
  category?: { id: number; name_en: string; name_bn: string | null };
  account?: { id: number; name: string };
}

export interface FundTransfer {
  id: number;
  transfer_no: string;
  from_account_id: number;
  to_account_id: number;
  amount: string;
  fee_amount: string;
  transfer_date: string;
  note: string | null;
  status: 'posted' | 'void';
  void_reason: string | null;
  from?: { id: number; name: string; account_type: AccountType };
  to?: { id: number; name: string; account_type: AccountType };
}

export interface DayBookAccount {
  account_id: number;
  code: string;
  name: string;
  account_type: AccountType;
  opening: string;
  in: string;
  out: string;
  closing: string;
}

export interface DayBookRow {
  id: number;
  date: string;
  time: string;
  direction: 'in' | 'out';
  amount: string;
  ref_type: string;
  ref_id: number | null;
  ref_no: string | null;
  description: string | null;
  account_id: number;
  account_name: string;
  party_type: PartyType | null;
  party_name: string | null;
}

export interface DayBook {
  data: { accounts: DayBookAccount[]; totals: { opening: string; in: string; out: string; closing: string }; rows: DayBookRow[] };
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}

export const BUCKETS = ['d0_15', 'd16_30', 'd31_60', 'd61_90', 'd90_plus'] as const;
export type Bucket = (typeof BUCKETS)[number];

export interface DuesRow {
  type: PartyType;
  id: number;
  code: string;
  name: string;
  phone: string | null;
  balance: string;
  buckets: Record<Bucket, string>;
  oldest_date: string | null;
  open_documents: number;
  last_payment: string | null;
  promise_date: string | null;
  promise_note: string | null;
}

export interface Dues {
  data: DuesRow[];
  summary: { parties: number; total: string; buckets: Record<Bucket, string> };
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}
