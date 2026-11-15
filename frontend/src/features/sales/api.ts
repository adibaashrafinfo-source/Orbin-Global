import { http, newIdempotencyKey, type Envelope } from '@/lib/api';
import type { Customer, CustomerOverview, CustomerVehicle, HeldBill, PosCard, PosConfig, PosVariant, Quotation, Sale, SaleReturn, SaleSummary, Shift, WarrantyClaim } from './types';

export interface Page<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

type Params = Record<string, unknown>;
const clean = (p: Params): Params => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== undefined && v !== null && v !== false));

export const SALES_KEYS = {
  config: ['pos', 'config'] as const,
  shift: ['shifts', 'current'] as const,
  shifts: ['shifts'] as const,
  held: ['sales', 'held'] as const,
  sales: ['sales'] as const,
  customers: ['customers'] as const,
  returns: ['sale-returns'] as const,
  quotations: ['quotations'] as const,
  warranty: ['warranty-claims'] as const,
};

export const posApi = {
  config: () => http.get<Envelope<PosConfig>>('/pos/config').then((r) => r.data.data),
  variants: (p: Params) => http.get<{ data: PosCard[]; meta: { total: number; warehouse_id: number } }>('/pos/variants', { params: clean(p) }).then((r) => r.data),
  scan: (code: string, warehouseId?: number) => http.get<Envelope<{ product: Omit<PosCard, 'variants'>; variant: PosCard['variants'][number] } | null>>('/pos/scan', { params: clean({ code, warehouse_id: warehouseId }) }).then((r) => r.data.data),
};

export interface CheckoutBody {
  lines: { variant_id: number; qty: string; unit_price: string; discount_type: string; discount_value: string; note?: string; override_reason?: string }[];
  customer_id: number;
  vehicle: Record<string, unknown> | null;
  sale_channel: string;
  salesperson_id?: number;
  warehouse_id: number;
  cart_discount: { type: string; value: string };
  delivery_charge: string;
  payments: Record<string, unknown>[];
  client_expected_grand_total: string;
  approvals?: { manager_pin?: string; reason?: string };
  note?: string;
  quotation_id?: number;
}

export const saleApi = {
  /** One key per attempt: a retry after a network drop reuses it, so the server returns the first invoice instead of a second. */
  checkout: (body: CheckoutBody, key: string) => http.post<Envelope<Sale>>('/sales/checkout', body, { headers: { 'Idempotency-Key': key } }).then((r) => r.data.data),
  list: (p: Params) => http.get<Page<Sale> & { summary: SaleSummary }>('/sales', { params: clean(p) }).then((r) => r.data),
  show: (id: number | string) => http.get<Envelope<Sale>>(`/sales/${id}`).then((r) => r.data.data),
  void: (id: number, reason: string) => http.post<Envelope<Sale>>(`/sales/${id}/void`, { reason }).then((r) => r.data.data),
  hold: (body: Params) => http.post<Envelope<{ id: number; held_name: string }>>('/sales/hold', body).then((r) => r.data.data),
  held: () => http.get<Envelope<HeldBill[]>>('/sales/held').then((r) => r.data.data),
  resume: (id: number) => http.post<Envelope<Record<string, unknown>>>(`/sales/${id}/resume`).then((r) => r.data.data),
  discard: (id: number) => http.delete(`/sales/${id}/held`).then(() => undefined),
  confirmPayment: (id: number) => http.post(`/sale-payments/${id}/confirm`).then(() => undefined),
  /** The invoice PDF as a blob (the API checks the session cookie), plus the file name the server chose. */
  pdf: (id: number, opts: { reprint?: boolean } = {}) => http.get<Blob>(`/sales/${id}/pdf`, { params: clean({ reprint: opts.reprint ? 1 : '' }), responseType: 'blob' }).then((r) => r.data),
  receiptUrl: (id: number) => `/api/v1/sales/${id}/receipt`,
  pdfUrl: (id: number) => `/api/v1/sales/${id}/pdf?inline=1`,
};

export const customerApi = {
  list: (p: Params) => http.get<Page<Customer>>('/customers', { params: clean(p) }).then((r) => r.data),
  show: (id: number | string) => http.get<Envelope<Customer>>(`/customers/${id}`).then((r) => r.data.data),
  overview: (id: number | string) => http.get<Envelope<CustomerOverview>>(`/customers/${id}/overview`).then((r) => r.data.data),
  create: (body: Params) => http.post<Envelope<Customer>>('/customers', body).then((r) => r.data.data),
  update: (id: number, body: Params) => http.put<Envelope<Customer>>(`/customers/${id}`, body).then((r) => r.data.data),
  remove: (id: number) => http.delete(`/customers/${id}`).then(() => undefined),
  addVehicle: (id: number, body: Params) => http.post<Envelope<CustomerVehicle>>(`/customers/${id}/vehicles`, body).then((r) => r.data.data),
  removeVehicle: (id: number) => http.delete(`/customer-vehicles/${id}`).then(() => undefined),
};

export const shiftApi = {
  current: () => http.get<Envelope<Shift | null>>('/shifts/current').then((r) => r.data.data),
  list: (p: Params) => http.get<Page<Shift>>('/shifts', { params: clean(p) }).then((r) => r.data),
  show: (id: number | string) => http.get<Envelope<Shift>>(`/shifts/${id}`).then((r) => r.data.data),
  open: (body: { cash_account_id: number; opening_float: string }) => http.post<Envelope<Shift>>('/shifts/open', body).then((r) => r.data.data),
  cash: (id: number, body: { direction: 'in' | 'out'; amount: string; reason: string }) => http.post<Envelope<Shift>>(`/shifts/${id}/cash`, body).then((r) => r.data.data),
  close: (id: number, body: { denomination_counts: Record<string, number>; note?: string }) => http.post<Envelope<Shift>>(`/shifts/${id}/close`, body).then((r) => r.data.data),
  zReportUrl: (id: number) => `/api/v1/shifts/${id}/z-report`,
};

export const returnApi = {
  list: (p: Params) => http.get<Page<SaleReturn>>('/sale-returns', { params: clean(p) }).then((r) => r.data),
  create: (body: Params) => http.post<Envelope<SaleReturn>>('/sale-returns', body).then((r) => r.data.data),
  approve: (id: number) => http.post<Envelope<SaleReturn>>(`/sale-returns/${id}/approve`).then((r) => r.data.data),
  cancel: (id: number) => http.post<Envelope<SaleReturn>>(`/sale-returns/${id}/cancel`).then((r) => r.data.data),
  pdfUrl: (id: number) => `/api/v1/sale-returns/${id}/pdf`,
};

export const quotationApi = {
  list: (p: Params) => http.get<Page<Quotation>>('/quotations', { params: clean(p) }).then((r) => r.data),
  show: (id: number | string) => http.get<Envelope<Quotation>>(`/quotations/${id}`).then((r) => r.data.data),
  create: (body: Params) => http.post<Envelope<Quotation>>('/quotations', body).then((r) => r.data.data),
  status: (id: number, status: string) => http.post<Envelope<Quotation>>(`/quotations/${id}/status`, { status }).then((r) => r.data.data),
  convert: (id: number, warehouseId: number) => http.post<Envelope<{ cart: { quotation_id: number; customer_id: number | null; vehicle: Record<string, string | null> | null; delivery_charge: string; lines: { variant_id: number; qty: string; unit_price: string; discount_type: string; discount_value: string }[] }; items: Record<string, { product: { name: string; image: string | null; warranty_months: number }; variant: PosVariant }>; shortages: { variant_id: number; sku: string; available: string; requested: string }[] }>>(`/quotations/${id}/convert`, { warehouse_id: warehouseId }).then((r) => r.data.data),
  pdfUrl: (id: number) => `/api/v1/quotations/${id}/pdf`,
};

export const warrantyApi = {
  list: (p: Params) => http.get<Page<WarrantyClaim>>('/warranty-claims', { params: clean(p) }).then((r) => r.data),
  create: (body: Params) => http.post<Envelope<WarrantyClaim>>('/warranty-claims', body).then((r) => r.data.data),
  update: (id: number, body: Params) => http.put<Envelope<WarrantyClaim>>(`/warranty-claims/${id}`, body).then((r) => r.data.data),
};

export { newIdempotencyKey };
