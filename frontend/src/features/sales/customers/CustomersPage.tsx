import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, Button, DataTable, EmptyState, Input, PageHeader, Pagination, Switch } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { customerApi, SALES_KEYS } from '../api';
import type { Customer } from '../types';
import { CustomerDialog } from './CustomerDialog';

/** FR-601, PRD §8.8: the customer list. A row opens the customer's page. */
export function CustomersPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const navigate = useNavigate();
  const canManage = can(['customers.manage']);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const [due, setDue] = useState(false);
  const [edit, setEdit] = useState<'new' | null>(null);
  const q = useDebouncedValue(search, 250);
  const params = { page, per_page: perPage, search: q, 'filter[with_due]': due };
  const query = useQuery({ queryKey: [...SALES_KEYS.customers, params], queryFn: () => customerApi.list(params), placeholderData: (p) => p });
  const columns = useMemo<ColumnDef<Customer, unknown>[]>(() => [
    { id: 'code', header: t('common.code'), cell: ({ row }) => <code className="text-xs font-semibold">{row.original.code}</code> },
    { id: 'name', header: t('customers.fields.name'), cell: ({ row }) => <div><div className="font-medium">{row.original.name}</div><div className="text-xs text-ink-muted">{row.original.company_name}</div></div> },
    { id: 'phone', header: t('customers.fields.phone'), cell: ({ row }) => row.original.phones[0] ?? '—' },
    { id: 'tier', header: t('customers.fields.tier'), cell: ({ row }) => <Badge tone={row.original.price_tier === 'retail' ? 'navy' : 'orange'}>{t(`customers.tiers.${row.original.price_tier}`)}</Badge> },
    { id: 'limit', header: t('customers.fields.creditLimit'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{Number(row.original.credit_limit) > 0 ? formatMoney(row.original.credit_limit) : '—'}</span> },
    { id: 'balance', header: t('customers.fields.balance'), cell: ({ row }) => { const b = Number(row.original.balance); return <span className={`tabular whitespace-nowrap font-semibold ${b > 0 ? 'text-orange-700' : b < 0 ? 'text-success' : 'text-ink-muted'}`}>{b < 0 ? t('customers.advanceAmount', { amount: formatMoney(String(-b)) }) : formatMoney(row.original.balance)}</span>; } },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={row.original.is_active ? 'success' : 'neutral'}>{row.original.is_walk_in ? t('customers.walkIn') : row.original.is_active ? t('common.active') : t('common.inactive')}</Badge> },
  ], [t]);
  const add = canManage ? <Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setEdit('new')}>{t('customers.add')}</Button> : null;
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.customers')} description={t('customers.subtitle')} crumbs={[{ label: t('nav.customers') }]} />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-72"><Input label={t('common.search')} type="search" placeholder={t('customers.searchHint')} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} /></div>
        <div className="pb-2"><Switch label={t('customers.withDue')} checked={due} onChange={(v) => { setDue(v); setPage(1); }} /></div>
        <div className="ms-auto">{add}</div>
      </div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(c) => String(c.id)} onRowClick={(c) => navigate(`/customers/${c.id}`)} compact caption={t('nav.customers')} empty={<EmptyState body={t('customers.empty')} action={add} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      <CustomerDialog customer={edit} canManage={canManage} onClose={(saved) => { setEdit(null); if (saved) navigate(`/customers/${saved.id}`); }} />
    </>
  );
}
