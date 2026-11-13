import { http, type Envelope } from '@/lib/api';
import type { DashboardData, Range } from './types';

export const DASHBOARD_KEY = ['dashboard'] as const;

export const dashboardApi = {
  get: (range: Range, from?: string, to?: string) => http.get<Envelope<DashboardData>>('/dashboard', { params: { range, ...(range === 'custom' ? { from, to } : {}) } }).then((r) => r.data.data),
};
