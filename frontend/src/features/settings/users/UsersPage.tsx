import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, DataTable, EmptyState, Input, PageHeader, Pagination, Select } from '@/design/components';
import { ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { ROLES } from '@/lib/permissions';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { usersApi, USERS_KEY, type User } from './api';
import { UserDialog } from './UserDialog';

/** FR-103 Users list with filters, create/edit, password reset, PIN, activate/deactivate. */
export function UsersPage() {
  const { t, i18n } = useTranslation();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const debounced = useDebouncedValue(search, 150);
  const query = useQuery({ queryKey: [...USERS_KEY, { page, perPage, search: debounced, role }], queryFn: () => usersApi.list({ page, per_page: perPage, search: debounced, role }), placeholderData: (prev) => prev });
  const [edit, setEdit] = useState<User | 'new' | null>(null);
  const ALL = '__all__';

  const columns = useMemo<ColumnDef<User, unknown>[]>(
    () => [
      { accessorKey: 'full_name', header: t('users.fullName'), cell: ({ row }) => <span className="font-medium text-ink">{row.original.full_name}</span> },
      { accessorKey: 'email', header: t('common.email') },
      { accessorKey: 'phone', header: t('common.phone'), cell: ({ getValue }) => getValue<string | null>() ?? '—' },
      { accessorKey: 'role', header: t('common.role'), cell: ({ getValue }) => <Badge tone="navy">{t(`users.roles.${getValue<string>()}`)}</Badge> },
      { id: 'pin', header: t('users.pin'), cell: ({ row }) => (row.original.has_override_pin ? <Badge tone="success">{t('users.pinSet')}</Badge> : <span className="text-ink-subtle">{t('users.pinNotSet')}</span>) },
      { accessorKey: 'last_login_at', header: t('users.lastLogin'), cell: ({ getValue }) => (getValue<string | null>() ? formatDateTime(getValue<string>(), i18n.language) : t('users.never')) },
      { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={row.original.is_active ? 'success' : 'neutral'}>{row.original.is_active ? t('common.active') : t('common.inactive')}</Badge> },
    ],
    [t, i18n.language],
  );

  return (
    <>
      <PageHeader title={t('users.title')} description={t('users.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('users.title') }]} actions={<Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setEdit('new')}>{t('users.addUser')}</Button>} />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <Input label={t('common.search')} type="search" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} className="w-64" />
        <Select label={t('common.role')} value={role || ALL} onChange={(v) => { setRole(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, ...ROLES.map((r) => ({ value: r, label: t(`users.roles.${r}`) }))]} className="w-48" />
      </div>
      <DataTable
        columns={columns}
        data={query.data?.data ?? []}
        loading={query.isLoading}
        error={query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: query.error instanceof ApiError ? query.error.requestId : undefined } : null}
        onRetry={() => void query.refetch()}
        onRowClick={setEdit}
        getRowId={(u) => String(u.id)}
        empty={<EmptyState body={t('users.empty')} />}
        caption={t('users.title')}
      />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      <UserDialog item={edit} onClose={() => setEdit(null)} />
    </>
  );
}
