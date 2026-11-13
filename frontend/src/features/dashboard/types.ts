export type Range = 'today' | '7d' | '30d' | 'month' | 'custom';

export interface Delta {
  change: string;
  percent: string | null;
}

export interface Point {
  date: string;
  total: string;
  invoices: number;
}

export interface TopRow {
  item?: string;
  make?: string;
  model?: string;
  qty: string;
  revenue: string;
}

export interface DashboardData {
  range: Range;
  from: string;
  to: string;
  previous: { from: string; to: string };
  widgets: {
    'kpi-sales'?: { value: string; previous: string; delta: Delta; spark: Point[] };
    'kpi-orders'?: { value: number | string; previous: number | string; delta: Delta; avg_ticket: string };
    'kpi-collections'?: { value: string; previous: string; delta: Delta };
    'kpi-profit'?: { value: string; previous: string; delta: Delta; margin: string | null };
    'kpi-dues'?: { receivable: string; payable: string; customers: number };
    'sales-trend'?: { points: Point[]; highlight: string };
    'payment-method-donut'?: { slices: { method: string; amount: string }[] };
    'top-products'?: { rows: TopRow[] };
    'top-vehicle-models'?: { rows: TopRow[] };
    'low-stock'?: { low: number; out: number; rows: { variant_id: number; sku: string; product: string; status: 'low' | 'out'; on_hand: string }[] };
    'account-balances'?: { rows: { id: number; name: string; type: string; balance: string }[] };
    'pending-approvals'?: { returns: number; adjustments: number; expenses: number; payments: number };
    'recent-sales'?: { rows: { id: number; invoice_no: string; at: string; total: string; status: string; payment_status: string; customer: string }[] };
  };
}
