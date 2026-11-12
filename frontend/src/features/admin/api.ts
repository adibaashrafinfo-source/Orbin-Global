import { http, type Envelope } from '@/lib/api';

export type CheckStatus = 'ok' | 'warn' | 'fail';
export interface HealthCheck {
  status: CheckStatus;
  detail: string;
  [extra: string]: unknown;
}
export interface HealthReport {
  overall: CheckStatus;
  app_version: string;
  app_env: string;
  debug: boolean;
  maintenance: boolean;
  last_cron_at: string | null;
  checked_at: string;
  checks: Record<string, HealthCheck>;
}

export const HEALTH_KEY = ['admin', 'health'] as const;

export const healthApi = {
  show: () => http.get<Envelope<HealthReport>>('/admin/health').then((r) => r.data.data),
  clearCaches: () => http.post('/admin/health/clear-caches').then(() => undefined),
  maintenance: (enabled: boolean) => http.post<Envelope<{ enabled: boolean; bypass_url?: string }>>('/admin/health/maintenance', { enabled }).then((r) => r.data.data),
  runMigrations: () => http.post<Envelope<{ ok: boolean; output: string }>>('/admin/health/run-migrations').then((r) => r.data.data),
};
