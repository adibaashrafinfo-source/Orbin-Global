import { http, type Paginated } from '@/lib/api';

export interface AuditEntry {
  id: number;
  occurred_at: string;
  actor: { id: number; full_name: string; role: string } | null;
  approver: { id: number; full_name: string } | null;
  action: string;
  entity_table: string;
  entity_id: number | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  reason: string | null;
  ip: string | null;
  request_id: string | null;
}

export interface AuditFilters {
  action?: string;
  entity_table?: string;
  from?: string;
  to?: string;
  search?: string;
}

export const AUDIT_KEY = ['audit-logs'] as const;

function params(f: AuditFilters, page: number, perPage: number) {
  return { page, per_page: perPage, search: f.search || undefined, 'filter[action]': f.action || undefined, 'filter[entity_table]': f.entity_table || undefined, 'filter[from]': f.from || undefined, 'filter[to]': f.to || undefined };
}

export const auditApi = {
  list: (f: AuditFilters, page: number, perPage: number) => http.get<Paginated<AuditEntry>>('/audit-logs', { params: params(f, page, perPage) }).then((r) => r.data),
  exportUrl: (f: AuditFilters) => {
    const q = new URLSearchParams();
    Object.entries(params(f, 1, 1)).forEach(([k, v]) => {
      if (v !== undefined && k !== 'page' && k !== 'per_page') q.set(k, String(v));
    });
    return `/api/v1/audit-logs/export?${q.toString()}`;
  },
};
