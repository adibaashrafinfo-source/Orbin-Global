/** Shapes shared by the inventory and purchasing screens. Quantities are strings with 3 places, money 2, unit costs 4. */

export interface Warehouse {
  id: number;
  code: string;
  name: string;
}

export interface Kpis {
  skus: number;
  units: string;
  low: number;
  out: number;
  /** Only present with products.view_cost. */
  value?: string;
}

export type StockStatus = 'ok' | 'low' | 'out' | 'over';

export interface OverviewRow {
  variant_id: number;
  sku: string;
  variant_name: string | null;
  barcode: string | null;
  product_id: number;
  product_name: string;
  on_hand: string;
  reserved: string;
  available: string;
  reorder_level: string;
  reorder_qty: string;
  max_stock: string | null;
  status: StockStatus;
  is_dead: number;
  last_sale: string | null;
  avg_cost?: string;
  stock_value?: string;
}

export interface MovementRow {
  id: number;
  occurred_at: string;
  movement_type: string;
  qty_delta: string;
  balance_after: string;
  unit_cost?: string;
  ref_table: string | null;
  ref_id: number | null;
  ref_no: string | null;
  reason: string | null;
  note: string | null;
  variant_id: number;
  sku: string;
  variant_name: string | null;
  warehouse_id: number;
  warehouse: string;
  user: string | null;
}

export interface CardRow {
  id: number;
  occurred_at: string;
  movement_type: string;
  qty_delta: string;
  running: string;
  unit_cost?: string;
  ref_no: string | null;
  ref_table: string | null;
  ref_id: number | null;
  reason: string | null;
  warehouse: string;
}

export interface StockCard {
  opening: string;
  closing: string;
  rows: CardRow[];
  variant: { id: number; sku: string; variant_name: string | null };
}

export interface BreakageRow {
  id: number;
  occurred_at: string;
  movement_type: string;
  qty: string;
  unit_cost?: string;
  cost_impact?: string;
  ref_no: string | null;
  reason: string | null;
  variant_id: number;
  sku: string;
  variant_name: string | null;
  product_name: string;
  user: string | null;
  supplier_id: number | null;
  supplier: string | null;
}

export interface ReorderRow {
  variant_id: number;
  sku: string;
  variant_name: string | null;
  product_name: string;
  on_hand: string;
  reorder_level: string;
  suggested_qty: string;
  supplier_id: number | null;
  supplier: string | null;
  last_cost?: string;
}

export interface AgingResult {
  buckets: Record<string, { qty: string; value?: string }>;
  rows: { variant_id: number; sku: string; variant_name: string | null; buckets: Record<string, string>; avg_cost?: string }[];
}

export interface ValuationResult {
  as_of: string;
  total: string;
  rows: { variant_id: number; warehouse_id: number; sku: string; variant_name: string | null; product_name: string; qty: string; avg_cost: string; value: string }[];
}

export interface Supplier {
  id: number;
  code: string;
  name: string;
  contact_person: string | null;
  phones: string[];
  email: string | null;
  address: string | null;
  city: string | null;
  country: string | null;
  supplier_type: 'local' | 'import';
  payment_terms_days: number;
  credit_limit: string;
  opening_balance: string;
  notes: string | null;
  is_active: boolean;
  balance: string;
}

/** One line of any document. Document-specific fields (qty_ordered, unit_cost, …) are plain string values. */
export interface DocLine {
  id?: number;
  variant_id: number;
  sku?: string;
  variant_name?: string | null;
  [key: string]: string | number | boolean | null | undefined;
}

export interface Doc {
  id: number;
  status: string;
  items?: DocLine[];
  extra_costs?: ExtraCost[];
  supplier?: { id: number; code: string; name: string } | null;
  warehouse?: { id: number; code: string; name: string } | null;
  from_warehouse?: { id: number; name: string } | null;
  to_warehouse?: { id: number; name: string } | null;
  reason?: { id: number; code: string; label_en: string; label_bn: string | null } | null;
  [key: string]: unknown;
}

export interface ExtraCost {
  id?: number;
  cost_type: string;
  description: string | null;
  amount: string;
  paid_to: string | null;
}

export interface Preview {
  lines: { variant_id: number; qty_received: string; qty_damaged: string; qty_good: string; unit_cost: string; line_total: string; allocated_extra: string; landed_unit_cost: string }[];
  items_total: string;
  extra_cost_total: string;
  grand_total: string;
  billed_extras: string;
}

export interface IntegrityCheck {
  key: string;
  status: 'ok' | 'warn' | 'fail';
  count: number;
  samples: Record<string, unknown>[];
}

export interface IntegrityResult {
  status: 'ok' | 'warn' | 'fail';
  ran_at: string;
  checks: IntegrityCheck[];
}

export const MOVEMENT_TYPES = ['opening', 'purchase_receipt', 'purchase_return', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out', 'transfer_out', 'transfer_in', 'breakage', 'damage', 'count_correction'] as const;
export const EXTRA_COST_TYPES = ['freight', 'customs_duty', 'clearing_forwarding', 'local_transport', 'insurance', 'other'] as const;
