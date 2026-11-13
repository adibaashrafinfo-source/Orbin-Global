import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Lock, LockOpen } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Card, CardBody, ErrorState, Input, PageHeader, PageSkeleton, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { apiMessage } from '@/features/catalog/useApiError';
import { lockApi, LEDGER_KEYS } from '@/features/ledger/api';
import { today } from '@/features/ledger/hooks';

/** FR-1009, BR-080: close the books up to a date. Nothing dated on or before it can be created, changed or voided. Only the owner may move it back. */
export function PeriodLockPage() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const { user } = useSession();
  const query = useQuery({ queryKey: LEDGER_KEYS.lock, queryFn: lockApi.get });
  const [date, setDate] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: (v: string | null) => lockApi.set(v),
    onSuccess: (r) => { client.setQueryData(LEDGER_KEYS.lock, r); setDate(null); toast.success(t(r.lock_date ? 'periodLock.locked' : 'periodLock.lifted')); },
    onError: (e) => toast.error(apiMessage(t, e)),
  });
  if (query.isLoading) return <PageSkeleton rows={4} />;
  if (query.isError || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const current = query.data.lock_date;
  const value = date ?? current ?? '';
  const isOwner = user?.role === 'super_admin';
  const backwards = current !== null && (value === '' || value < current);
  return (
    <>
      <PageHeader title={t('periodLock.title')} description={t('periodLock.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('periodLock.title') }]} />
      <Card className="max-w-xl"><CardBody className="flex flex-col gap-4">
        <p className="flex items-center gap-2 text-sm" data-testid="lock-state">
          {current ? <Lock className="h-4 w-4 text-navy-800" aria-hidden="true" /> : <LockOpen className="h-4 w-4 text-ink-muted" aria-hidden="true" />}
          {current ? t('periodLock.lockedUpTo', { date: current }) : t('periodLock.notLocked')}
        </p>
        <Input label={t('periodLock.lockUpTo')} type="date" max={today()} value={value} onChange={(e) => setDate(e.target.value)} hint={t('periodLock.hint')} />
        {backwards && !isOwner && <p role="alert" className="text-sm text-danger">{t('periodLock.onlyOwnerBack')}</p>}
        <div className="flex justify-end gap-2">
          {current && isOwner && <Button variant="secondary" loading={save.isPending} onClick={() => save.mutate(null)}>{t('periodLock.lift')}</Button>}
          <Button loading={save.isPending} disabled={value === '' || value === current || (backwards && !isOwner)} onClick={() => save.mutate(value)} data-testid="lock-save">{t('periodLock.lock')}</Button>
        </div>
      </CardBody></Card>
    </>
  );
}
