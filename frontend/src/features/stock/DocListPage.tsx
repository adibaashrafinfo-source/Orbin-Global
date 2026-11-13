import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Button, DataTable, EmptyState, Input, Pagination, Select } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { ApiError } from '@/lib/api';
import { docApi, STOCK_KEYS } from './api';
import type { Doc } from './types';

const ALL = '__all__';

interface Props {
  resource: string;
  /** Route of one document; `new` goes to `${base}/new`. */
  base: string;
  statuses: string[];
  statusLabel: (s: string) => string;
  columns: ColumnDef<Doc, unknown>[];
  createLabel: string;
  createPermission: string[];
  emptyText: string;
  caption: string;
  header?: ReactNode;
  /** Extra filters rendered beside the status select. */
  extraFilters?: ReactNode;
  filterParams?: Record<string, unknown>;
}

/** The list every document type shares: search, status filter, table, paging, "new" button. Rows open the document. */
export function DocListPage({ resource, base, statuses, statusLabel, columns, createLabel, createPermission, emptyText, caption, header, extraFilters, filterParams }: Props) {
  const { t } = useTranslation();
  const { can } = useSession();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const q = useDebouncedValue(search, 250);
  const params = { page, per_page: perPage, search: q, 'filter[status]': status, ...filterParams };
  const query = useQuery({ queryKey: [...STOCK_KEYS.docs(resource), params], queryFn: () => docApi(resource).list(params), placeholderData: (prev) => prev });
  const cols = useMemo(() => columns, [columns]);
  const add = can(createPermission) ? <Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => navigate(`${base}/new`)}>{createLabel}</Button> : null;
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: query.error instanceof ApiError ? query.error.requestId : undefined } : null;
  return (
    <>
      {header}
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-64"><Input label={t('common.search')} type="search" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} /></div>
        <div className="w-52"><Select label={t('common.status')} value={status || ALL} onChange={(v) => { setStatus(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, ...statuses.map((s) => ({ value: s, label: statusLabel(s) }))]} /></div>
        {extraFilters}
        <div className="ms-auto">{add}</div>
      </div>
      <DataTable columns={cols} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(d) => String(d.id)} onRowClick={(d) => navigate(`${base}/${d.id}`)} caption={caption} empty={<EmptyState body={emptyText} action={add} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
    </>
  );
}
