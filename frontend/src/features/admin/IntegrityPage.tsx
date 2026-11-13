import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, ShieldAlert, ShieldCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Card, CardBody, EmptyState, PageHeader, Skeleton, toast } from '@/design/components';
import { useApiError } from '../catalog/useApiError';
import { formatDateTime } from '@/lib/format';
import { integrityApi } from '../stock/api';

const KEY = ['integrity'] as const;

/** FR-113: the nightly "do the books still add up" check, with a button to run it now. */
export function IntegrityPage() {
  const { t, i18n } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const last = useQuery({ queryKey: KEY, queryFn: integrityApi.last });
  const run = useMutation({ mutationFn: integrityApi.run, onSuccess: (r) => { client.setQueryData(KEY, r); toast.success(r.status === 'fail' ? t('integrity.found') : t('integrity.clean')); }, onError });
  const data = last.data;
  const tone = data?.status === 'ok' ? 'success' : data?.status === 'warn' ? 'warning' : 'danger';
  return (
    <>
      <PageHeader title={t('integrity.title')} description={t('integrity.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('integrity.title') }]} actions={<Button loading={run.isPending} onClick={() => run.mutate()}>{t('integrity.runNow')}</Button>} />
      {last.isLoading ? <Skeleton className="h-40 w-full" /> : !data ? <EmptyState body={t('integrity.never')} /> : (
        <div className="grid gap-4">
          <Card><CardBody className="flex items-center gap-3">
            {data.status === 'ok' ? <ShieldCheck className="h-6 w-6 text-success" aria-hidden="true" /> : <ShieldAlert className="h-6 w-6 text-danger" aria-hidden="true" />}
            <div><Badge tone={tone}>{t(`integrity.status.${data.status}`)}</Badge><p className="mt-1 text-xs text-ink-muted">{t('integrity.lastRun', { when: formatDateTime(data.ran_at, i18n.language) })}</p></div>
          </CardBody></Card>
          <ul className="grid gap-3" aria-label={t('integrity.title')}>
            {data.checks.map((c) => (
              <li key={c.key}><Card><CardBody>
                <div className="flex items-center justify-between gap-3">
                  <span className="flex items-center gap-2 font-medium">{c.status === 'ok' ? <CheckCircle2 className="h-4 w-4 text-success" aria-hidden="true" /> : <ShieldAlert className={c.status === 'warn' ? 'h-4 w-4 text-warning' : 'h-4 w-4 text-danger'} aria-hidden="true" />}{t(`integrity.checks.${c.key}`)}</span>
                  <Badge tone={c.status === 'ok' ? 'success' : c.status === 'warn' ? 'warning' : 'danger'}>{c.status === 'ok' ? t('integrity.status.ok') : t('integrity.problems', { count: c.count })}</Badge>
                </div>
                {c.samples.length > 0 && (
                  // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- a scrollable region must be reachable by keyboard
                  <pre className="mt-3 max-h-48 overflow-auto rounded-input bg-navy-50 p-3 text-xs" tabIndex={0}>{JSON.stringify(c.samples, null, 2)}</pre>
                )}
              </CardBody></Card></li>
            ))}
          </ul>
        </div>
      )}
    </>
  );
}
