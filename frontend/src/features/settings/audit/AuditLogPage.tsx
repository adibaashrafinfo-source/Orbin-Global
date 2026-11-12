import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, DataTable, Drawer, EmptyState, Input, PageHeader, Pagination, Select } from '@/design/components';
import { ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { auditApi, AUDIT_KEY, type AuditEntry, type AuditFilters } from './api';

const ACTIONS = ['create', 'update', 'delete', 'void', 'price_change', 'stock_adjust', 'override', 'permission_change', 'login', 'login_failed', 'logout', 'password_change', 'password_reset', 'pin_change', 'pin_failed', 'admin_action', 'export'];
const ALL = '__all__';

/** FR-108 Audit log: filters, pagination, before/after detail, CSV export. */
export function AuditLogPage() {
  const { t, i18n } = useTranslation();
  const [filters, setFilters] = useState<AuditFilters>({});
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const search = useDebouncedValue(filters.search ?? '', 150);
  const effective = { ...filters, search };
  const query = useQuery({ queryKey: [...AUDIT_KEY, effective, page, perPage], queryFn: () => auditApi.list(effective, page, perPage), placeholderData: (prev) => prev });
  const [detail, setDetail] = useState<AuditEntry | null>(null);
  const update = (patch: Partial<AuditFilters>) => {
    setFilters((f) => ({ ...f, ...patch }));
    setPage(1);
  };
  const tone = (a: string) => (a.includes('fail') || a === 'delete' || a === 'void' ? 'danger' : a === 'permission_change' || a === 'override' || a === 'price_change' ? 'orange' : a === 'create' ? 'success' : 'navy');

  const columns = useMemo<ColumnDef<AuditEntry, unknown>[]>(
    () => [
      { accessorKey: 'occurred_at', header: t('audit.occurredAt'), cell: ({ getValue }) => <span className="whitespace-nowrap tabular">{formatDateTime(getValue<string>(), i18n.language)}</span> },
      { id: 'actor', header: t('audit.actor'), cell: ({ row }) => row.original.actor?.full_name ?? <span className="text-ink-subtle">{t('audit.system')}</span> },
      { accessorKey: 'action', header: t('audit.action'), cell: ({ getValue }) => <Badge tone={tone(getValue<string>())}>{getValue<string>()}</Badge> },
      { id: 'entity', header: t('audit.entity'), cell: ({ row }) => <code className="text-13">{row.original.entity_table}{row.original.entity_id ? ` #${row.original.entity_id}` : ''}</code> },
      { accessorKey: 'reason', header: t('audit.reason'), cell: ({ getValue }) => getValue<string | null>() ?? '—' },
      { accessorKey: 'ip', header: t('audit.ip'), cell: ({ getValue }) => <span className="tabular text-ink-muted">{getValue<string | null>() ?? '—'}</span> },
    ],
    [t, i18n.language],
  );

  return (
    <>
      <PageHeader title={t('audit.title')} description={t('audit.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('audit.title') }]} actions={<Button variant="secondary" leftIcon={<Download className="h-4 w-4" aria-hidden="true" />} onClick={() => window.open(auditApi.exportUrl(effective), '_blank', 'noopener')}>{t('audit.export')}</Button>} />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <Input label={t('common.search')} type="search" value={filters.search ?? ''} onChange={(e) => update({ search: e.target.value })} className="w-56" />
        <Select label={t('audit.action')} value={filters.action || ALL} onChange={(v) => update({ action: v === ALL ? undefined : v })} options={[{ value: ALL, label: t('audit.allActions') }, ...ACTIONS.map((a) => ({ value: a, label: a }))]} className="w-48" />
        <Input label={t('audit.entity')} value={filters.entity_table ?? ''} onChange={(e) => update({ entity_table: e.target.value })} className="w-44" />
        <Input label={t('audit.dateFrom')} type="date" value={filters.from ?? ''} onChange={(e) => update({ from: e.target.value })} />
        <Input label={t('audit.dateTo')} type="date" value={filters.to ?? ''} onChange={(e) => update({ to: e.target.value })} />
      </div>
      <DataTable
        columns={columns}
        data={query.data?.data ?? []}
        loading={query.isLoading}
        error={query.error ? { message: t('errors.unknown'), requestId: query.error instanceof ApiError ? query.error.requestId : undefined } : null}
        onRetry={() => void query.refetch()}
        onRowClick={setDetail}
        getRowId={(r) => String(r.id)}
        compact
        empty={<EmptyState body={t('audit.empty')} />}
        caption={t('audit.title')}
      />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      <Drawer open={detail !== null} onOpenChange={(o) => !o && setDetail(null)} title={t('audit.details')} description={detail ? `${detail.action} · ${detail.entity_table}${detail.entity_id ? ` #${detail.entity_id}` : ''}` : undefined}>
        {detail && (
          <dl className="grid gap-4 text-sm">
            <div><dt className="text-xs font-semibold uppercase text-ink-subtle">{t('audit.occurredAt')}</dt><dd className="tabular">{formatDateTime(detail.occurred_at, i18n.language)}</dd></div>
            <div><dt className="text-xs font-semibold uppercase text-ink-subtle">{t('audit.actor')}</dt><dd>{detail.actor ? `${detail.actor.full_name} (${t(`users.roles.${detail.actor.role}`)})` : t('audit.system')}</dd></div>
            {detail.reason && <div><dt className="text-xs font-semibold uppercase text-ink-subtle">{t('audit.reason')}</dt><dd>{detail.reason}</dd></div>}
            <div><dt className="text-xs font-semibold uppercase text-ink-subtle">{t('audit.before')}</dt><dd><pre className="max-h-64 overflow-auto rounded-input bg-navy-50 p-3 text-xs">{detail.before ? JSON.stringify(detail.before, null, 2) : '—'}</pre></dd></div>
            <div><dt className="text-xs font-semibold uppercase text-ink-subtle">{t('audit.after')}</dt><dd><pre className="max-h-64 overflow-auto rounded-input bg-navy-50 p-3 text-xs">{detail.after ? JSON.stringify(detail.after, null, 2) : '—'}</pre></dd></div>
            <div><dt className="text-xs font-semibold uppercase text-ink-subtle">{t('errors.requestId')}</dt><dd><code className="text-xs">{detail.request_id ?? '—'}</code></dd></div>
          </dl>
        )}
      </Drawer>
    </>
  );
}
