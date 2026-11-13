import { http, type Envelope } from '@/lib/api';
import type { Filters, Preset, ReportResponse, ReportSummary } from './types';

type Params = Record<string, unknown>;
const clean = (p: Params): Params => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== undefined && v !== null && v !== false));

export const REPORT_KEYS = { catalog: ['reports', 'catalog'] as const, run: ['reports', 'run'] as const, presets: ['reports', 'presets'] as const };

/** The query a report takes, from the filters on screen. */
export function reportParams(f: Filters, perPage = 50): Params {
  return clean({ from: f.from, to: f.to, as_of: f.asOf, branch_id: f.branchId, warehouse_id: f.warehouseId, group: f.group, variant_id: f.variantId, compare: f.compare ? 1 : '', sort: f.sort, dir: f.sort ? f.dir : '', page: f.page, per_page: perPage });
}

export const reportApi = {
  catalog: () => http.get<Envelope<ReportSummary[]>>('/reports').then((r) => r.data.data),
  run: (id: string, f: Filters) => http.get<ReportResponse>(`/reports/${id}`, { params: reportParams(f) }).then((r) => r.data),
  /** A link the browser can open (cookie session): used for the inline PDF a person prints from. */
  exportUrl: (id: string, f: Filters, format: 'csv' | 'xlsx' | 'pdf', inline = false) => {
    const { page: _p, per_page: _pp, sort: _s, dir: _d, compare: _c, ...rest } = reportParams(f) as Record<string, unknown>;
    const q = new URLSearchParams(Object.entries(rest).map(([k, v]) => [k, String(v)]));
    q.set('format', format);
    if (inline) q.set('inline', '1');
    return `/api/v1/reports/${id}/export?${q.toString()}`;
  },
  file: (id: string, f: Filters, format: 'csv' | 'xlsx' | 'pdf') => http.get<Blob>(reportApi.exportUrl(id, f, format).replace('/api/v1', ''), { responseType: 'blob' }).then((r) => r.data),
  presets: (reportId: string) => http.get<Envelope<Preset[]>>('/report-presets', { params: { report_id: reportId } }).then((r) => r.data.data),
  savePreset: (body: { report_id: string; name: string; filters: Preset['filters'] }) => http.post<Envelope<Preset>>('/report-presets', body).then((r) => r.data.data),
  deletePreset: (id: number) => http.delete(`/report-presets/${id}`).then(() => undefined),
};
