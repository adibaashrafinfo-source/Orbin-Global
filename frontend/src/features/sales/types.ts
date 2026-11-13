export type PriceTier = 'retail' | 'wholesale' | 'dealer';
export type DiscountType = 'none' | 'percent' | 'amount';
export type PaymentMethod = 'cash' | 'bkash' | 'nagad' | 'rocket' | 'bank_transfer';

export interface PosVariant {
  id: number;
  sku: string;
  barcode: string | null;
  variant_name: string | null;
  position: string;
  size_label: string | null;
  adjustment: string | null;
  is_bundle: boolean;
  retail_price: string;
  wholesale_price: string | null;
  dealer_price: string | null;
  min_price: string | null;
  tax_rate: string;
  available: string;
  features: string[];
}

export interface PosCard {
  product_id: number;
  name: string;
  name_bn: string | null;
  code: string;
  mirror_type: string;
  brand: string | null;
  quality: string | null;
  warranty_months: number;
  image: string | null;
  price_from: string;
  price_to: string;
  variants: PosVariant[];
}

export interface FinancialAccount {
  id: number;
  name: string;
  account_type: 'cash' | 'bkash' | 'nagad' | 'rocket' | 'bank';
  account_number: string | null;
  provider: string | null;
  is_default_for_method: boolean;
}

export interface Shift {
  id: number;
  shift_no: string;
  cashier_id: number;
  cashier?: string;
  cash_account_id: number;
  status: 'open' | 'closed';
  opened_at: string;
  closed_at: string | null;
  opening_float: string;
  expected_cash: string | null;
  counted_cash: string | null;
  variance: string | null;
  denomination_counts: Record<string, number> | null;
  note: string | null;
  has_z_report: boolean;
  totals: ShiftTotals | null;
}

export interface ShiftTotals {
  sales_count: number;
  sales_total: string;
  void_count: number;
  void_total: string;
  by_method: Record<string, { count: number; total: string }>;
  opening_float: string;
  cash_sales: string;
  cash_refunds: string;
  cash_in: string;
  cash_out: string;
  cash_collections: string;
  cash_paid_out: string;
  expected_cash: string;
}

export interface PosConfig {
  settings: {
    vat_mode: 'inclusive' | 'exclusive';
    rounding_mode: 'none' | 'nearest_1';
    cashier_max_discount_percent: number;
    credit_limit_mode: 'warn' | 'block';
    require_open_shift: boolean;
    driver_side: 'left' | 'right';
    pos_auto_download_invoice: boolean;
    pos_auto_reset_seconds: number;
    require_verification_for_mfs: boolean;
    default_return_window_days: number;
    receipt_width_mm: number;
    duplicate_trx_days: number;
    numeral_system: 'latn' | 'beng';
  };
  default_tax_rate: string;
  warehouses: { id: number; name: string; is_default: boolean }[];
  accounts: FinancialAccount[];
  walk_in_customer_id: number;
  shift: Shift | null;
  company_ready: boolean;
  company_missing: string[];
  can: { price_override: boolean; sell_below_floor: boolean; discount_over_limit: boolean; view_cost: boolean };
}

export interface CustomerVehicle {
  id: number;
  reg_no: string;
  make_id: number | null;
  model_id: number | null;
  year: number | null;
  color: string | null;
  note: string | null;
  make?: string | null;
  model?: string | null;
}

export interface Customer {
  id: number;
  code: string;
  customer_type_id: number | null;
  price_tier: PriceTier;
  name: string;
  company_name: string | null;
  phones: string[];
  whatsapp: string | null;
  email: string | null;
  address_line1: string | null;
  address_line2: string | null;
  area: string | null;
  district: string | null;
  postcode: string | null;
  nid_no: string | null;
  trade_license_no: string | null;
  bin_no: string | null;
  credit_limit: string;
  opening_balance: string;
  notes: string | null;
  is_walk_in: boolean;
  is_active: boolean;
  phone_primary: string | null;
  balance: string;
  vehicles?: CustomerVehicle[];
}

export interface SaleItem {
  id: number;
  line_no: number;
  variant_id: number;
  product_name_snapshot: string;
  sku_snapshot: string;
  spec_snapshot: Record<string, unknown>;
  qty: string;
  qty_returned: string;
  unit_price: string;
  price_tier_used: PriceTier;
  discount_amount: string;
  cart_discount_share: string;
  tax_rate_percent: string;
  tax_amount: string;
  line_total: string;
  warranty_months: number;
  warranty_until: string | null;
  bundle_parent_line: number | null;
  note: string | null;
  unit_cost?: string;
}

export interface SalePayment {
  id: number;
  method: PaymentMethod;
  amount: string;
  tendered: string | null;
  change_amount: string;
  account_id: number;
  sender_number: string | null;
  trx_id: string | null;
  bank_name: string | null;
  bank_ref: string | null;
  verification: 'confirmed' | 'pending' | 'rejected';
  received_at: string;
}

export type SaleStatus = 'draft' | 'held' | 'completed' | 'void' | 'partially_returned' | 'returned';

export interface Sale {
  id: number;
  invoice_no: string;
  verify_token: string;
  customer_id: number;
  customer_snapshot: { name: string; code: string; phones?: string[]; is_walk_in?: boolean; company_name?: string | null };
  vehicle_snapshot: { reg_no?: string; make?: string; model?: string; year?: string; color?: string } | null;
  sale_channel: string;
  status: SaleStatus;
  sale_date: string;
  subtotal: string;
  line_discount_total: string;
  cart_discount_amount: string;
  taxable_amount: string;
  tax_total: string;
  delivery_charge: string;
  round_off: string;
  grand_total: string;
  paid_total: string;
  due_total: string;
  change_given: string;
  payment_status: 'unpaid' | 'partial' | 'paid';
  cashier?: string;
  salesperson?: string;
  note: string | null;
  reprint_count: number;
  void_reason: string | null;
  has_pdf: boolean;
  items?: SaleItem[];
  payments?: SalePayment[];
  pdf_url?: string;
  receipt_url?: string;
  download_name?: string;
  returns?: { id: number; return_no: string; status: string; refund_total: string; ledger_credit_total: string; return_date: string }[];
  timeline?: { occurred_at: string; action: string; reason: string | null }[];
}

export interface SaleSummary {
  count: number;
  grand_total: string;
  paid_total: string;
  due_total: string;
}

export interface SaleReturn {
  id: number;
  return_no: string;
  sale_id: number;
  invoice_no?: string;
  status: 'draft' | 'pending_approval' | 'posted' | 'cancelled';
  refund_mode: string;
  refund_method: string | null;
  refund_total: string;
  ledger_credit_total: string;
  subtotal: string;
  tax_total: string;
  return_date: string;
  note: string | null;
  has_pdf: boolean;
}

export interface Quotation {
  id: number;
  quote_no: string;
  customer_snapshot: { name: string; code: string; phones?: string[]; is_walk_in?: boolean };
  status: 'draft' | 'sent' | 'accepted' | 'expired' | 'converted' | 'cancelled';
  quote_date: string;
  valid_until: string | null;
  subtotal: string;
  discount_total: string;
  tax_total: string;
  delivery_charge: string;
  grand_total: string;
  note: string | null;
  converted_sale_id: number | null;
  has_pdf: boolean;
  items?: { id: number; variant_id: number; description_snapshot: string; qty: string; unit_price: string; line_total: string }[];
}

export interface WarrantyClaim {
  id: number;
  claim_no: string;
  sale_item_id: number;
  status: 'open' | 'in_review' | 'replaced' | 'refunded' | 'rejected';
  issue: string;
  resolution: string | null;
  claimed_at: string;
  resolved_at: string | null;
  customer: string | null;
  invoice_no: string | null;
  product: string | null;
}

export interface HeldBill {
  id: number;
  held_name: string;
  customer: string | null;
  held_at: string;
  line_count: number;
}

export interface CustomerOverview {
  customer: Customer;
  balance: string;
  due: string;
  advance: string;
  lifetime_value: string;
  invoice_count: number;
  last_purchase_at: string | null;
  invoices: { id: number; invoice_no: string; sale_date: string; status: SaleStatus; grand_total: string; paid_total: string; due_total: string; payment_status: string }[];
  ledger: { id: number; entry_date: string; entry_type: string; debit: string; credit: string; ref_no: string | null; description: string | null }[];
  top_products: { name: string; qty: string }[];
}
