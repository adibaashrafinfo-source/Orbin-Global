export interface ReportSummary {
  id: string;
  name: string;
  domain: 'sales' | 'profit' | 'inventory' | 'purchase' | 'finance' | 'tax' | 'audit';
  options: { range?: 'range' | 'asof' | 'none'; branch?: boolean; warehouse?: boolean; compare?: boolean; group?: string[]; variant?: boolean; search?: boolean };
  chart: { type: 'line' | 'bar'; x: string; y: string } | null;
  needs_cost: boolean;
}

export interface ReportColumn {
  key: string;
  label: string;
  type: 'text' | 'date' | 'datetime' | 'int' | 'qty' | 'money' | 'cost' | 'percent';
  sum: boolean;
}

export type ReportRow = Record<string, unknown> & { _links?: Record<string, { table: string; id: number }> };

export interface ReportResult extends ReportSummary {
  columns: ReportColumn[];
  rows: ReportRow[];
  totals: Record<string, string>;
  compare: { from: string; to: string; totals: Record<string, string>; delta: Record<string, { change: string; percent: string | null }> } | null;
}

export interface ReportResponse {
  data: ReportResult;
  meta: { page: number; per_page: number; total: number; filters: Record<string, unknown> };
}

export interface Preset {
  id: number;
  report_id: string;
  name: string;
  filters: { range?: RangeKey | null; from?: string | null; to?: string | null; branch_id?: number | null; warehouse_id?: number | null; group?: string | null; compare?: boolean };
}

export type RangeKey = 'today' | 'week' | 'month' | 'last_month' | 'custom';

export interface Filters {
  range: RangeKey;
  from: string;
  to: string;
  asOf: string;
  branchId: string;
  warehouseId: string;
  group: string;
  variantId: string;
  compare: boolean;
  sort: string;
  dir: 'asc' | 'desc';
  page: number;
}
