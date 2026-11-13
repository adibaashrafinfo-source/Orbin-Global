import { Inbox, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from './Button';

/** Designed empty state with illustration and optional action. */
export function EmptyState({ title, body, action, icon }: { title?: ReactNode; body?: ReactNode; action?: ReactNode; icon?: ReactNode }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
      <div className="flex h-16 w-16 items-center justify-center rounded-pill bg-navy-50 text-navy-400">{icon ?? <Inbox className="h-8 w-8" aria-hidden="true" />}</div>
      <h3 className="font-heading text-base font-bold text-navy-900">{title ?? t('empty.title')}</h3>
      <p className="max-w-sm text-13 text-ink-muted">{body ?? t('empty.body')}</p>
      {action}
    </div>
  );
}

/** Designed error state with retry and the request id for support. */
export function ErrorState({ title, body, requestId, onRetry }: { title?: ReactNode; body?: ReactNode; requestId?: string | undefined; onRetry?: () => void }) {
  const { t } = useTranslation();
  return (
    <div role="alert" className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
      <div className="flex h-16 w-16 items-center justify-center rounded-pill bg-danger/10 text-danger">
        <TriangleAlert className="h-8 w-8" aria-hidden="true" />
      </div>
      <h3 className="font-heading text-base font-bold text-navy-900">{title ?? t('errors.unknown')}</h3>
      {body && <p className="max-w-sm text-13 text-ink-muted">{body}</p>}
      {requestId && (
        <p className="text-xs text-ink-muted">
          {t('errors.requestId')}: <code className="rounded bg-navy-50 px-1 py-0.5">{requestId}</code>
        </p>
      )}
      {onRetry && (
        <Button variant="secondary" size="sm" onClick={onRetry}>
          {t('common.retry')}
        </Button>
      )}
    </div>
  );
}
