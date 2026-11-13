import { useQuery } from '@tanstack/react-query';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { ErrorState, PageHeader, Skeleton } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { todayIso } from '@/features/reports/util';
import { COMPANY_KEY, companyApi } from '@/features/settings/company/api';
import { dashboardApi, DASHBOARD_KEY } from './api';
import { BottomRow } from './BottomRow';
import { KpiRow } from './KpiRow';
import { RangeControl } from './RangeControl';
import { TopRow } from './TopRow';
import { TrendRow } from './TrendRow';
import type { Range } from './types';

/** PRD §8.2: the role-aware dashboard. The server decides which widgets a person gets; a role with none sees a short welcome. */
export function DashboardPage() {
  const { t } = useTranslation();
  const { user, can } = useSession();
  const [range, setRange] = useState<Range>('today');
  const [from, setFrom] = useState(todayIso());
  const [to, setTo] = useState(todayIso());
  const query = useQuery({ queryKey: [...DASHBOARD_KEY, range, range === 'custom' ? from : '', range === 'custom' ? to : ''], queryFn: () => dashboardApi.get(range, from, to), placeholderData: (p) => p, refetchInterval: 60_000, enabled: range !== 'custom' || from <= to });
  const company = useQuery({ queryKey: COMPANY_KEY, queryFn: companyApi.show, enabled: can('settings.company.manage') });
  const w = query.data?.widgets;
  const empty = w !== undefined && Object.keys(w).length === 0;
  const previous = t('dashboard.kpi.vsPrevious');

  return (
    <>
      <PageHeader title={t('dashboard.greeting', { name: user?.full_name ?? '' })} description={t('dashboard.subtitle')} />
      {company.data && !company.data.invoice_ready && (
        <Link to="/settings/company" className="mb-5 flex items-center gap-3 rounded-card border border-orange-300 bg-orange-50 px-4 py-3 text-sm text-orange-800 focus-visible:focus-ring-light"><AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />{t('dashboard.companyIncomplete')}</Link>
      )}
      {!empty && <div className="mb-5"><RangeControl range={range} from={from} to={to} onChange={(r, f, tt) => { setRange(r); if (f) setFrom(f); if (tt) setTo(tt); }} /></div>}
      {query.isLoading ? <div className="grid gap-4"><Skeleton className="h-32" /><Skeleton className="h-64" /></div> : query.isError ? <ErrorState onRetry={() => void query.refetch()} /> : w && (
        empty ? <p className="text-sm text-ink-muted">{t('dashboard.nothing')}</p> : (
          <>
            <KpiRow w={w} previousLabel={previous} />
            <TrendRow w={w} />
            <TopRow w={w} />
            <BottomRow w={w} />
          </>
        )
      )}
    </>
  );
}
