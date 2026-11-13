import { http, type Envelope } from '@/lib/api';
import type { AgingResult, BreakageRow, Doc, IntegrityResult, Kpis, MovementRow, OverviewRow, Preview, ReorderRow, StockCard, Supplier, ValuationResult, Warehouse } from './types';

export const STOCK_KEYS = {
  warehouses: ['warehouses'] as const,
  inventory: ['inventory'] as const,
  docs: (resource: string) => ['docs', resource] as const,
  doc: (resource: string, id: number | string) => ['docs', resource, id] as const,
  suppliers: ['suppliers'] as const,
};

export interface Page<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

type Params = Record<string, unknown>;
const clean = (p: Params): Params => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== undefined && v !== null));

export const warehouseApi = { list: () => http.get<Envelope<Warehouse[]>>('/warehouses').then((r) => r.data.data) };

export const stockApi = {
  overview: (p: Params) => http.get<Page<OverviewRow> & { meta: { kpis: Kpis } }>('/inventory/overview', { params: clean(p) }).then((r) => r.data),
  movements: (p: Params) => http.get<Page<MovementRow>>('/inventory/movements', { params: clean(p) }).then((r) => r.data),
  card: (variantId: number, p: Params) => http.get<Envelope<StockCard>>(`/inventory/stock-card/${variantId}`, { params: clean(p) }).then((r) => r.data.data),
  valuation: (p: Params) => http.get<Envelope<ValuationResult>>('/inventory/valuation', { params: clean(p) }).then((r) => r.data.data),
  aging: (p: Params) => http.get<Envelope<AgingResult>>('/inventory/aging', { params: clean(p) }).then((r) => r.data.data),
  breakage: (p: Params) => http.get<{ data: BreakageRow[]; meta: { count: number; cost_impact?: string } }>('/inventory/breakage', { params: clean(p) }).then((r) => r.data),
  reorder: (warehouseId?: string) => http.get<Envelope<ReorderRow[]>>('/inventory/reorder', { params: clean({ warehouse_id: warehouseId }) }).then((r) => r.data.data),
  createPurchaseOrders: (lines: { variant_id: number; qty: string; supplier_id: number }[]) =>
    http.post<Envelope<{ id: number; po_no: string; supplier_id: number; grand_total: string }[]>>('/inventory/reorder/purchase-orders', { lines }).then((r) => r.data.data),
  /** A same-origin link the browser downloads with its cookie (the API checks the session). */
  csvUrl: (path: string, p: Params) => `/api/v1/${path}?${new URLSearchParams({ ...(clean(p) as Record<string, string>), format: 'csv' }).toString()}`,
};

/** Documents (orders, receipts, returns, adjustments, transfers, counts) all expose the same CRUD plus named actions. */
export function docApi(resource: string) {
  return {
    list: (p: Params) => http.get<Page<Doc>>(`/${resource}`, { params: clean(p) }).then((r) => r.data),
    show: (id: number | string) => http.get<Envelope<Doc>>(`/${resource}/${id}`).then((r) => r.data.data),
    create: (body: Params) => http.post<Envelope<Doc>>(`/${resource}`, body).then((r) => r.data.data),
    update: (id: number, body: Params) => http.put<Envelope<Doc>>(`/${resource}/${id}`, body).then((r) => r.data.data),
    remove: (id: number) => http.delete(`/${resource}/${id}`).then(() => undefined),
    act: (id: number, action: string, body?: Params) => http.post<Envelope<Doc>>(`/${resource}/${id}/${action}`, body ?? {}).then((r) => r.data.data),
  };
}

export const supplierApi = {
  list: (p: Params) => http.get<Page<Supplier>>('/suppliers', { params: clean(p) }).then((r) => r.data),
  create: (body: Params) => http.post<Envelope<Supplier>>('/suppliers', body).then((r) => r.data.data),
  update: (id: number, body: Params) => http.put<Envelope<Supplier>>(`/suppliers/${id}`, body).then((r) => r.data.data),
  remove: (id: number) => http.delete(`/suppliers/${id}`).then(() => undefined),
};

export const purchaseApi = {
  preview: (body: Params) => http.post<Envelope<Preview>>('/goods-receipts/preview', body).then((r) => r.data.data),
  receiptDraft: (poId: number) =>
    http.get<Envelope<{ po_id: number; supplier_id: number; status: string; items: { po_item_id: number; variant_id: number; sku: string; variant_name: string | null; qty_ordered: string; qty_received_so_far: string; qty_received: string; qty_damaged: string; unit_cost: string }[] }>>(`/purchase-orders/${poId}/receipt-draft`).then((r) => r.data.data),
  shareLink: (poId: number) => http.post<Envelope<{ url: string; expires_at: string }>>(`/purchase-orders/${poId}/share-link`).then((r) => r.data.data),
  history: (variantId: number, supplierId?: number) =>
    http.get<Envelope<{ date: string; grn_no: string; supplier_id: number; supplier: string; unit_cost: string; landed_unit_cost: string; qty_received: string }[]>>(`/variants/${variantId}/purchase-history`, { params: clean({ supplier_id: supplierId }) }).then((r) => r.data.data),
  pdfUrl: (poId: number) => `/api/v1/purchase-orders/${poId}/pdf`,
};

export const countApi = {
  enter: (id: number, items: { variant_id: number; counted_qty: string | null }[]) => http.put<Envelope<Doc>>(`/stock-counts/${id}/items`, { items }).then((r) => r.data.data),
  addItem: (id: number, variantId: number) => http.post<Envelope<Doc>>(`/stock-counts/${id}/items`, { variant_id: variantId }).then((r) => r.data.data),
};

export const adjustmentApi = {
  photo: (id: number, file: File) => {
    const form = new FormData();
    form.append('file', file);
    return http.post<Envelope<{ id: number; file_name: string }>>(`/stock-adjustments/${id}/photos`, form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data);
  },
  photoUrl: (attachmentId: number) => `/api/v1/adjustment-photos/${attachmentId}`,
};

export const integrityApi = {
  last: () => http.get<Envelope<IntegrityResult | null>>('/admin/health/integrity').then((r) => r.data.data),
  run: () => http.post<Envelope<IntegrityResult>>('/admin/health/integrity/run').then((r) => r.data.data),
};
