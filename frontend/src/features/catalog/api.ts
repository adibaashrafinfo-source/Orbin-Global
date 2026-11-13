import { http, type Envelope, type Paginated } from '@/lib/api';
import type { CrossReference, CustomFieldDefRow, Fitment, LookupTypeRow, LookupValueRow, MasterRow, Product, ProductImage, Variant } from './types';

export const KEYS = {
  lookups: ['lookups'] as const,
  customFields: ['custom-fields'] as const,
  master: (resource: string) => ['master', resource] as const,
  products: ['products'] as const,
  product: (id: number) => ['products', id] as const,
};

export interface MasterResourceName {
  name: 'vehicle-makes' | 'vehicle-models' | 'product-brands' | 'categories' | 'units' | 'tax-rates';
}

export const masterApi = {
  list: (resource: string, params?: Record<string, unknown>) =>
    http.get<{ data: MasterRow[]; meta: { total: number } }>(`/${resource}`, { params }).then((r) => r.data.data),
  create: (resource: string, body: Record<string, unknown>) => http.post<Envelope<MasterRow>>(`/${resource}`, body).then((r) => r.data.data),
  update: (resource: string, id: number, body: Record<string, unknown>) => http.put<Envelope<MasterRow>>(`/${resource}/${id}`, body).then((r) => r.data.data),
  remove: (resource: string, id: number) => http.delete(`/${resource}/${id}`).then(() => undefined),
  reorder: (resource: string, ids: number[]) => http.put(`/${resource}/reorder`, { ids }).then(() => undefined),
};

export const lookupApi = {
  list: () => http.get<Envelope<LookupTypeRow[]>>('/lookups').then((r) => r.data.data),
  createType: (body: { key: string; name_en: string; name_bn?: string | null; description?: string | null }) => http.post<Envelope<LookupTypeRow>>('/lookups', body).then((r) => r.data.data),
  updateType: (id: number, body: Partial<LookupTypeRow>) => http.put<Envelope<LookupTypeRow>>(`/lookups/${id}`, body).then((r) => r.data.data),
  removeType: (id: number) => http.delete(`/lookups/${id}`).then(() => undefined),
  createValue: (typeId: number, body: Partial<LookupValueRow>) => http.post<Envelope<LookupValueRow>>(`/lookups/${typeId}/values`, body).then((r) => r.data.data),
  updateValue: (id: number, body: Partial<LookupValueRow>) => http.put<Envelope<LookupValueRow>>(`/lookup-values/${id}`, body).then((r) => r.data.data),
  removeValue: (id: number) => http.delete(`/lookup-values/${id}`).then(() => undefined),
  reorderValues: (typeId: number, ids: number[]) => http.put(`/lookups/${typeId}/values/reorder`, { ids }).then(() => undefined),
};

export const customFieldApi = {
  list: (entity?: string) => http.get<Envelope<CustomFieldDefRow[]>>('/custom-fields', { params: { entity } }).then((r) => r.data.data),
  create: (body: Partial<CustomFieldDefRow>) => http.post<Envelope<CustomFieldDefRow>>('/custom-fields', body).then((r) => r.data.data),
  update: (id: number, body: Partial<CustomFieldDefRow>) => http.put<Envelope<CustomFieldDefRow>>(`/custom-fields/${id}`, body).then((r) => r.data.data),
  remove: (id: number) => http.delete(`/custom-fields/${id}`).then(() => undefined),
  reorder: (ids: number[]) => http.put('/custom-fields/reorder', { ids }).then(() => undefined),
};

export interface ProductListParams {
  page: number;
  per_page: number;
  search?: string;
  sort?: string;
  filter: Record<string, string>;
}

export const productApi = {
  list: (p: ProductListParams) => {
    const params: Record<string, unknown> = { page: p.page, per_page: p.per_page, search: p.search || undefined, sort: p.sort };
    for (const [k, v] of Object.entries(p.filter)) if (v !== '') params[`filter[${k}]`] = v;
    return http.get<Paginated<Product>>('/products', { params }).then((r) => r.data);
  },
  show: (id: number) => http.get<Envelope<Product>>(`/products/${id}`).then((r) => r.data.data),
  create: (body: Partial<Product>) => http.post<Envelope<Product>>('/products', body).then((r) => r.data.data),
  update: (id: number, body: Partial<Product>) => http.put<Envelope<Product>>(`/products/${id}`, body).then((r) => r.data.data),
  remove: (id: number) => http.delete(`/products/${id}`).then(() => undefined),
  publish: (id: number) => http.post<Envelope<Product>>(`/products/${id}/publish`).then((r) => r.data.data),
  deactivate: (id: number, confirm = false) => http.post<Envelope<Product>>(`/products/${id}/deactivate`, { confirm }).then((r) => r.data.data),
  clone: (id: number) => http.post<Envelope<Product>>(`/products/${id}/clone`, {}).then((r) => r.data.data),
};

export interface MatrixSpec {
  positions: string[];
  sizes: { width_mm?: number | null; height_mm?: number | null; thickness_mm?: number | null; label?: string }[];
  color_finish_ids: number[];
  feature_sets: Record<string, unknown>[];
  defaults: Record<string, unknown>;
}
export interface MatrixPlanRow {
  position: string;
  size_label: string | null;
  sku: string;
  variant_name: string;
  duplicate: boolean;
  existing_variant_id: number | null;
}

export const variantApi = {
  show: (id: number) => http.get<Envelope<Variant>>(`/variants/${id}`).then((r) => r.data.data),
  create: (productId: number, body: Record<string, unknown>) => http.post<Envelope<Variant>>(`/products/${productId}/variants`, body).then((r) => r.data.data),
  update: (id: number, body: Record<string, unknown>) => http.put<Envelope<Variant>>(`/variants/${id}`, body).then((r) => r.data.data),
  remove: (id: number) => http.delete(`/variants/${id}`).then(() => undefined),
  clone: (id: number, body: Record<string, unknown>) => http.post<Envelope<Variant>>(`/variants/${id}/clone`, body).then((r) => r.data.data),
  barcode: (id: number) => http.post<Envelope<Variant>>(`/variants/${id}/barcode`).then((r) => r.data.data),
  matrix: (productId: number, spec: MatrixSpec, dryRun: boolean) =>
    http.post<Envelope<{ plan: MatrixPlanRow[]; created: number[]; skipped: number }>>(`/products/${productId}/variants/matrix`, { ...spec, dry_run: dryRun }).then((r) => r.data.data),
  search: (q: string, limit = 20) => http.get<{ data: Variant[] }>('/variants/search', { params: { q, limit } }).then((r) => r.data.data),
  addCrossRef: (variantId: number, body: { number: string; ref_type?: string; brand?: string | null }) => http.post<Envelope<CrossReference>>(`/variants/${variantId}/cross-references`, body).then((r) => r.data.data),
  removeCrossRef: (id: number) => http.delete(`/cross-references/${id}`).then(() => undefined),
  addFitment: (variantId: number, body: Record<string, unknown>) => http.post<Envelope<{ created: number; skipped: number }>>(`/variants/${variantId}/fitments`, body).then((r) => r.data.data),
  removeFitment: (id: number) => http.delete(`/fitments/${id}`).then(() => undefined),
  copyFitments: (variantId: number, targets: number[]) => http.post<Envelope<{ created: number; skipped: number }>>(`/variants/${variantId}/fitments/copy`, { target_variant_ids: targets }).then((r) => r.data.data),
  addBundleItem: (variantId: number, componentId: number, qty: string) => http.post<Envelope<Variant>>(`/variants/${variantId}/bundle-items`, { component_variant_id: componentId, qty }).then((r) => r.data.data),
  removeBundleItem: (id: number) => http.delete(`/bundle-items/${id}`).then(() => undefined),
  bulkPrice: (body: Record<string, unknown>) => http.post<Envelope<{ count: number; changed: number; sample: { variant_id: number; sku: string; before: Record<string, string>; after: Record<string, string> }[] }>>('/variants/bulk-price', body).then((r) => r.data.data),
};

export const imageApi = {
  upload: (productId: number, file: File, opts: { variantId?: number | null; label?: string }) => {
    const form = new FormData();
    form.append('file', file);
    if (opts.variantId) form.append('variant_id', String(opts.variantId));
    if (opts.label) form.append('label', opts.label);
    return http.post<Envelope<ProductImage>>(`/products/${productId}/images`, form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data);
  },
  update: (id: number, body: { label?: string; alt_text?: string | null }) => http.put<Envelope<ProductImage>>(`/images/${id}`, body).then((r) => r.data.data),
  primary: (id: number) => http.post(`/images/${id}/primary`).then(() => undefined),
  reorder: (productId: number, ids: number[]) => http.put(`/products/${productId}/images/reorder`, { ids }).then(() => undefined),
  remove: (id: number) => http.delete(`/images/${id}`).then(() => undefined),
};

export interface ImportErrorRow {
  row: number;
  field: string;
  code: string;
  value: string | null;
}
export interface ImportSummary {
  kind: string;
  total_rows: number;
  valid_rows: number;
  invalid_rows: number;
  errors: ImportErrorRow[];
  errors_truncated: boolean;
  error_count: number;
  would_create: number;
  would_update: number;
  preview: Record<string, string>[];
  file_name: string;
  committed?: boolean;
  created?: number;
  updated?: number;
}

export const importApi = {
  dryRun: (kind: string, file: File) => {
    const form = new FormData();
    form.append('kind', kind);
    form.append('file', file);
    return http.post<Envelope<{ token: string; summary: ImportSummary }>>('/imports/dry-run', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data);
  },
  commit: (kind: string, token: string) => http.post<Envelope<ImportSummary>>('/imports/commit', { kind, token }).then((r) => r.data.data),
  templateUrl: (kind: string, format: 'csv' | 'xlsx') => `/api/v1/imports/templates/${kind}?format=${format}`,
  exportUrl: (format: 'csv' | 'xlsx', search?: string) => `/api/v1/products-export?format=${format}${search ? `&search=${encodeURIComponent(search)}` : ''}`,
};

export const labelApi = {
  render: (body: Record<string, unknown>) => http.post<Blob>('/labels', body, { responseType: 'blob' }).then((r) => r.data),
};

export type { Fitment };
