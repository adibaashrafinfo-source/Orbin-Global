import { useQuery } from '@tanstack/react-query';
import { BarChart3, Boxes, FileSearch, Percent, Receipt, ShoppingBag, Wallet, type LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Card, CardBody, EmptyState, ErrorState, Input, PageHeader, Skeleton } from '@/design/components';
import { REPORT_KEYS, reportApi } from './api';
import type { ReportSummary } from './types';

const DOMAINS = ['sales', 'profit', 'inventory', 'purchase', 'finance', 'tax', 'audit'] as const;
const ICON: Record<(typeof DOMAINS)[number], LucideIcon> = { sales: Receipt, profit: BarChart3, inventory: Boxes, purchase: ShoppingBag, finance: Wallet, tax: Percent, audit: FileSearch };

/** PRD §8.10: the report gallery, grouped by domain, with search. Each person sees only the reports their role may open. */
export function ReportsHubPage() {
  const { t } = useTranslation();
  const [search, setSearch] = useState('');
  const query = useQuery({ queryKey: REPORT_KEYS.catalog, queryFn: reportApi.catalog, staleTime: 5 * 60_000 });
  const groups = useMemo(() => {
    const needle = search.trim().toLowerCase();
    const match = (r: ReportSummary) => needle === '' || t(`reports.name.${r.id}`, { defaultValue: r.name }).toLowerCase().includes(needle) || r.id.includes(needle);

    return DOMAINS.map((d) => ({ domain: d, items: (query.data ?? []).filter((r) => r.domain === d && match(r)) })).filter((g) => g.items.length > 0);
  }, [query.data, search, t]);
  return (
    <>
      <PageHeader title={t('nav.reports')} description={t('reports.hub.subtitle')} crumbs={[{ label: t('nav.reports') }]} />
      <div className="mb-5 max-w-sm"><Input type="search" label={t('common.search')} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('reports.hub.searchHint')} /></div>
      {query.isLoading ? <Skeleton className="h-64" /> : query.isError ? <ErrorState onRetry={() => void query.refetch()} /> : groups.length === 0 ? <EmptyState body={t('reports.hub.empty')} /> : (
        <div className="flex flex-col gap-8">
          {groups.map(({ domain, items }) => {
            const Icon = ICON[domain];
            return (
              <section key={domain} aria-labelledby={`dom-${domain}`}>
                <h2 id={`dom-${domain}`} className="mb-3 flex items-center gap-2 font-heading text-base font-bold text-navy-900"><span className="rounded-pill bg-navy-100 p-2 text-navy-800"><Icon className="h-4 w-4" aria-hidden="true" /></span>{t(`reports.domains.${domain}`)}</h2>
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                  {items.map((r) => (
                    <li key={r.id}>
                      <Link to={`/reports/${r.id}`} className="group block rounded-card focus-visible:focus-ring-light" data-testid={`report-card-${r.id}`}>
                        <Card interactive className="mirror-shine h-full"><CardBody className="flex items-center justify-between gap-3"><span className="font-medium text-navy-900">{t(`reports.name.${r.id}`, { defaultValue: r.name })}</span><span className="text-xs text-ink-muted">{t(`reports.domains.${r.domain}`)}</span></CardBody></Card>
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            );
          })}
        </div>
      )}
    </>
  );
}
