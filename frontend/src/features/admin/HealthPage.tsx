import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, RefreshCw, TriangleAlert, XCircle } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Card, CardBody, CardHeader, ConfirmDialog, ErrorState, PageHeader, PageSkeleton, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { formatDateTime, formatRelative } from '@/lib/format';
import { healthApi, HEALTH_KEY, type CheckStatus } from './api';

const ORDER = ['php', 'extensions', 'limits', 'database', 'writable', 'env_safety', 'https', 'cron', 'queue', 'disk', 'backup', 'migrations'] as const;
const LABEL: Record<(typeof ORDER)[number], string> = { php: 'php', extensions: 'extensions', limits: 'limits', database: 'database', writable: 'writable', env_safety: 'envSafety', https: 'https', cron: 'lastCron', queue: 'queue', disk: 'disk', backup: 'backup', migrations: 'pendingMigrations' };

function StatusIcon({ status }: { status: CheckStatus }) {
  if (status === 'ok') return <CheckCircle2 className="h-5 w-5 text-success" aria-hidden="true" />;
  if (status === 'warn') return <TriangleAlert className="h-5 w-5 text-warning" aria-hidden="true" />;
  return <XCircle className="h-5 w-5 text-danger" aria-hidden="true" />;
}

/** FR-112 Hosting health: checks, cron heartbeat, audited admin actions (each behind a confirm dialog). */
export function HealthPage() {
  const { t, i18n } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: HEALTH_KEY, queryFn: healthApi.show, refetchInterval: 60_000 });
  const [confirm, setConfirm] = useState<'caches' | 'maintenanceOn' | 'maintenanceOff' | 'migrations' | null>(null);
  const done = () => {
    void client.invalidateQueries({ queryKey: HEALTH_KEY });
    toast.success(t('health.done'));
    setConfirm(null);
  };
  const fail = (e: unknown) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') }));
  const caches = useMutation({ mutationFn: healthApi.clearCaches, onSuccess: done, onError: fail });
  const maintenance = useMutation({
    mutationFn: healthApi.maintenance,
    onSuccess: (r) => {
      done();
      if (r.enabled && r.bypass_url) window.open(r.bypass_url, '_blank', 'noopener');
    },
    onError: fail,
  });
  const migrations = useMutation({ mutationFn: healthApi.runMigrations, onSuccess: done, onError: fail });

  if (query.isLoading) return <PageSkeleton rows={12} />;
  if (query.error || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const report = query.data;
  const statusLabel = (s: CheckStatus) => t(`health.${s}`);
  const tone = (s: CheckStatus) => (s === 'ok' ? 'success' : s === 'warn' ? 'warning' : 'danger');

  return (
    <>
      <PageHeader
        title={t('health.title')}
        description={t('health.subtitle')}
        crumbs={[{ label: t('nav.admin') }, { label: t('health.title') }]}
        actions={
          <>
            <Badge tone={tone(report.overall)}>{statusLabel(report.overall)}</Badge>
            <Button variant="secondary" size="sm" leftIcon={<RefreshCw className="h-4 w-4" aria-hidden="true" />} onClick={() => void query.refetch()} loading={query.isFetching}>{t('health.refresh')}</Button>
          </>
        }
      />
      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <Card>
          <CardHeader title={t('health.checks')} description={`${t('health.appVersion')} ${report.app_version} · ${report.app_env}`} />
          <ul className="divide-y divide-navy-100">
            {ORDER.map((key) => {
              const c = report.checks[key];
              if (!c) return null;
              const extra = key === 'cron' && report.last_cron_at ? ` · ${formatRelative(report.last_cron_at, i18n.language)}` : '';
              return (
                <li key={key} className="flex items-start gap-3 px-5 py-3">
                  <StatusIcon status={c.status} />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-ink">{t(`health.${LABEL[key]}`)}</p>
                    <p className="text-13 text-ink-muted">{c.detail}{extra}</p>
                    {key === 'cron' && c.status !== 'ok' && <p className="mt-1 text-xs text-danger">{report.last_cron_at ? t('health.cronStale') : t('health.cronNever')}</p>}
                    {key === 'limits' && typeof c.values === 'object' && c.values !== null && (
                      <p className="mt-1 text-xs tabular text-ink-muted">{Object.entries(c.values as Record<string, string>).map(([k, v]) => `${k}=${v}`).join(' · ')}</p>
                    )}
                  </div>
                  <Badge tone={tone(c.status)}>{statusLabel(c.status)}</Badge>
                </li>
              );
            })}
          </ul>
        </Card>
        <Card className="self-start">
          <CardHeader title={t('health.actions')} />
          <CardBody className="flex flex-col gap-3">
            <div className="flex items-center justify-between text-sm">
              <span>{t('health.maintenance')}</span>
              <Badge tone={report.maintenance ? 'warning' : 'success'}>{report.maintenance ? t('common.yes') : t('common.no')}</Badge>
            </div>
            <Button variant="secondary" block onClick={() => setConfirm('caches')}>{t('health.clearCaches')}</Button>
            {report.maintenance ? (
              <Button variant="secondary" block onClick={() => setConfirm('maintenanceOff')}>{t('health.maintenanceOff')}</Button>
            ) : (
              <Button variant="secondary" block onClick={() => setConfirm('maintenanceOn')}>{t('health.maintenanceOn')}</Button>
            )}
            <Button variant="secondary" block onClick={() => setConfirm('migrations')}>{t('health.runMigrations')}</Button>
            <p className="text-xs text-ink-muted">{t('health.lastCron')}: {report.last_cron_at ? formatDateTime(report.last_cron_at, i18n.language) : t('health.cronNever')}</p>
          </CardBody>
        </Card>
      </div>
      <ConfirmDialog open={confirm === 'caches'} onOpenChange={(o) => !o && setConfirm(null)} title={t('health.clearCaches')} body={t('health.clearCachesConfirm')} loading={caches.isPending} onConfirm={() => caches.mutate()} />
      <ConfirmDialog open={confirm === 'maintenanceOn'} onOpenChange={(o) => !o && setConfirm(null)} title={t('health.maintenanceOn')} body={t('health.maintenanceOnConfirm')} danger typeToConfirm="MAINTENANCE" loading={maintenance.isPending} onConfirm={() => maintenance.mutate(true)} />
      <ConfirmDialog open={confirm === 'maintenanceOff'} onOpenChange={(o) => !o && setConfirm(null)} title={t('health.maintenanceOff')} body={t('health.maintenanceOffConfirm')} loading={maintenance.isPending} onConfirm={() => maintenance.mutate(false)} />
      <ConfirmDialog open={confirm === 'migrations'} onOpenChange={(o) => !o && setConfirm(null)} title={t('health.runMigrations')} body={t('health.runMigrationsConfirm')} typeToConfirm="MIGRATE" loading={migrations.isPending} onConfirm={() => migrations.mutate()} />
    </>
  );
}
