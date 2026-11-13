import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';
import { EmptyState, ErrorState, PageHeader, Pagination, Skeleton } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { ApiError } from '@/lib/api';
import { REPORT_KEYS, reportApi } from './api';
import { ExportMenu } from './ExportMenu';
import { FilterBar } from './FilterBar';
import { PresetBar } from './PresetBar';
import { ReportChart } from './ReportChart';
import { ReportTable } from './ReportTable';
import { SummaryTiles } from './SummaryTiles';
import { useReportFilters } from './useReportFilters';

/** One report: filters, presets, totals with an optional comparison, a chart where it helps, the table and the exports (FR-1100). */
export function ReportPage() {
  const { t } = useTranslation();
  const { id = '' } = useParams();
  const { can } = useSession();
  const [f, set] = useReportFilters();
  const catalog = useQuery({ queryKey: REPORT_KEYS.catalog, queryFn: reportApi.catalog, staleTime: 5 * 60_000 });
  const summary = catalog.data?.find((r) => r.id === id);
  const query = useQuery({ queryKey: [...REPORT_KEYS.run, id, f], queryFn: () => reportApi.run(id, f), enabled: summary !== undefined, placeholderData: (p) => p });
  const name = t(`reports.name.${id}`, { defaultValue: summary?.name ?? id });
  if (catalog.isLoading) return <Skeleton className="h-64" />;
  if (summary === undefined) return <ErrorState title={t('errors.forbidden')} />;
  const data = query.data?.data;
  const meta = query.data?.meta;
  const sortBy = (key: string) => set({ sort: key, dir: f.sort === key && f.dir === 'asc' ? 'desc' : 'asc', page: 1 });
  const fail = query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : null;
  return (
    <>
      <PageHeader title={name} description={t(`reports.domains.${summary.domain}`)} crumbs={[{ label: t('nav.reports'), to: '/reports' }, { label: name }]} actions={can(['reports.export']) ? <ExportMenu id={id} filters={f} /> : undefined} />
      <FilterBar def={summary} f={f} onChange={set} />
      <div className="mb-4"><PresetBar reportId={id} filters={f} onApply={set} /></div>
      {query.isLoading ? <Skeleton className="h-64" /> : query.isError ? <ErrorState body={fail ?? undefined} onRetry={() => void query.refetch()} /> : data && meta && (
        <>
          <SummaryTiles def={data} />
          <ReportChart def={data} />
          {data.rows.length === 0 ? <EmptyState body={summary.options.variant && f.variantId === '' ? t('reports.pickVariant') : t('reports.empty')} /> : (
            <>
              <ReportTable columns={data.columns} rows={data.rows} totals={data.totals} sort={f.sort} dir={f.dir} onSort={sortBy} />
              <Pagination page={meta.page} perPage={meta.per_page} total={meta.total} onPageChange={(p) => set({ page: p })} />
            </>
          )}
        </>
      )}
    </>
  );
}
