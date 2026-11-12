import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('skeleton h-4 w-full', className)} aria-hidden="true" />;
}

/** Page-level loading: skeletons, never spinners (designSystem.motion.required #7). */
export function PageSkeleton({ rows = 6 }: { rows?: number }) {
  const { t } = useTranslation();
  return (
    <div role="status" aria-label={t('a11y.loading')} className="flex flex-col gap-4">
      <Skeleton className="h-8 w-1/3" />
      <div className="card flex flex-col gap-3 p-5">
        {Array.from({ length: rows }).map((_, i) => (
          <Skeleton key={i} className={i % 3 === 2 ? 'w-2/3' : 'w-full'} />
        ))}
      </div>
    </div>
  );
}

export function TableSkeleton({ rows = 5, cols = 4 }: { rows?: number; cols?: number }) {
  const { t } = useTranslation();
  return (
    <div role="status" aria-label={t('a11y.loading')} className="flex flex-col divide-y divide-navy-100">
      {Array.from({ length: rows }).map((_, r) => (
        <div key={r} className="flex items-center gap-4 px-4 py-4">
          {Array.from({ length: cols }).map((__, c) => (
            <Skeleton key={c} className={c === 0 ? 'w-1/4' : 'flex-1'} />
          ))}
        </div>
      ))}
    </div>
  );
}
