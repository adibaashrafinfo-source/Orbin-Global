import { useMutation, useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Ban, Check, ListTree, Plus, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, Button, DataTable, EmptyState, IconButton, Input, PageHeader, Pagination, Select, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { StopClick } from '@/features/catalog/StopClick';
import { useApiError } from '@/features/catalog/useApiError';
import { fmtDateTime } from '@/features/sales/format';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { expenseApi, LEDGER_KEYS } from './api';
import { categoryName, useCategories } from './categories';
import { CategoriesDialog, ExpenseDialog } from './ExpenseDialogs';
import { useRefreshMoney } from './hooks';
import { LedgerTabs } from './LedgerTabs';
import { ReasonDialog } from './ReasonDialog';
import type { Expense } from './types';

const ALL = '__all__';
const TONE = { pending_approval: 'warning', posted: 'success', rejected: 'danger', void: 'neutral' } as const;

/** FR-1005: every expense with its status, the approval step and the void. */
export function ExpensesPage() {
  const { t, i18n } = useTranslation();
  const { user } = useSession();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const categories = useCategories();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [f, setF] = useState({ search: '', status: '', category: '', from: '', to: '' });
  const [dialog, setDialog] = useState<'new' | 'categories' | null>(null);
  const [acting, setActing] = useState<{ expense: Expense; kind: 'reject' | 'void' } | null>(null);
  const q = useDebouncedValue(f.search, 250);
  const params = { page, per_page: perPage, search: q, 'filter[status]': f.status, 'filter[category_id]': f.category, 'filter[from]': f.from, 'filter[to]': f.to };
  const query = useQuery({ queryKey: [...LEDGER_KEYS.expenses, params], queryFn: () => expenseApi.list(params), placeholderData: (p) => p });
  const set = (patch: Partial<typeof f>) => { setF((s) => ({ ...s, ...patch })); setPage(1); };
  const approve = useMutation({ mutationFn: (e: Expense) => expenseApi.approve(e.id), onSuccess: () => { refresh(); toast.success(t('ledger.expenses.approved')); }, onError });
  const decide = useMutation({
    mutationFn: (reason: string) => (acting!.kind === 'reject' ? expenseApi.reject(acting!.expense.id, reason) : expenseApi.void(acting!.expense.id, reason)),
    onSuccess: () => { refresh(); toast.success(t(acting!.kind === 'reject' ? 'ledger.expenses.rejected' : 'ledger.expenses.voided')); setActing(null); },
    onError,
  });
  const columns = useMemo<ColumnDef<Expense, unknown>[]>(() => [
    { id: 'no', header: t('ledger.expenses.no'), cell: ({ row }) => <span className="font-semibold text-navy-900">{row.original.expense_no}</span> },
    { id: 'date', header: t('ledger.date'), cell: ({ row }) => fmtDateTime(row.original.expense_date, i18n.language, false) },
    { id: 'cat', header: t('ledger.expenses.category'), cell: ({ row }) => (row.original.category ? categoryName(row.original.category, i18n.language) : '—') },
    { id: 'payee', header: t('ledger.expenses.payee'), cell: ({ row }) => <div>{row.original.payee ?? '—'}{row.original.note && <div className="max-w-xs truncate text-xs text-ink-muted">{row.original.note}</div>}</div> },
    { id: 'acct', header: t('ledger.account'), cell: ({ row }) => row.original.account?.name ?? '—' },
    { id: 'amount', header: t('ledger.amount'), cell: ({ row }) => <span className="tabular whitespace-nowrap font-semibold">{formatMoney(row.original.amount)}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={TONE[row.original.status]}>{t(`ledger.expenses.status.${row.original.status}`)}</Badge> },
    { id: 'actions', header: () => <span className="sr-only">{t('common.actions')}</span>, cell: ({ row }) => {
      const e = row.original;
      const mayApprove = e.status === 'pending_approval' && (e.created_by !== user?.id || user?.role === 'super_admin');
      return (
        <StopClick className="flex justify-end gap-1">
          {mayApprove && <IconButton label={t('ledger.expenses.approve')} onClick={() => approve.mutate(e)}><Check className="h-4 w-4" aria-hidden="true" /></IconButton>}
          {e.status === 'pending_approval' && <IconButton label={t('ledger.expenses.reject')} onClick={() => setActing({ expense: e, kind: 'reject' })}><X className="h-4 w-4" aria-hidden="true" /></IconButton>}
          {e.status === 'posted' && <IconButton label={t('ledger.expenses.void')} onClick={() => setActing({ expense: e, kind: 'void' })}><Ban className="h-4 w-4" aria-hidden="true" /></IconButton>}
        </StopClick>
      );
    } },
  // eslint-disable-next-line react-hooks/exhaustive-deps -- approve is a stable mutation trigger
  ], [t, i18n.language, user?.id, user?.role]);
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.ledger')} description={t('ledger.expenses.subtitle')} crumbs={[{ label: t('nav.ledger') }, { label: t('ledger.tabs.expenses') }]}
        actions={<><Button variant="secondary" leftIcon={<ListTree className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('categories')}>{t('ledger.expenses.categories')}</Button><Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('new')} data-testid="add-expense">{t('ledger.expenses.add')}</Button></>} />
      <LedgerTabs />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-56"><Input label={t('common.search')} type="search" value={f.search} onChange={(e) => set({ search: e.target.value })} /></div>
        <div className="w-44"><Select label={t('common.status')} value={f.status || ALL} onChange={(v) => set({ status: v === ALL ? '' : v })} options={[{ value: ALL, label: t('common.all') }, ...(['pending_approval', 'posted', 'rejected', 'void'] as const).map((x) => ({ value: x, label: t(`ledger.expenses.status.${x}`) }))]} /></div>
        <div className="w-52"><Select label={t('ledger.expenses.category')} value={f.category || ALL} onChange={(v) => set({ category: v === ALL ? '' : v })} options={[{ value: ALL, label: t('common.all') }, ...(categories.data ?? []).map((c) => ({ value: String(c.id), label: categoryName(c, i18n.language) }))]} /></div>
        <div className="w-40"><Input label={t('ledger.from')} type="date" value={f.from} onChange={(e) => set({ from: e.target.value })} /></div>
        <div className="w-40"><Input label={t('ledger.to')} type="date" value={f.to} onChange={(e) => set({ to: e.target.value })} /></div>
      </div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(r) => String(r.id)} compact caption={t('ledger.tabs.expenses')} empty={<EmptyState body={t('ledger.expenses.empty')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      {dialog === 'new' && <ExpenseDialog open onClose={() => setDialog(null)} />}
      {dialog === 'categories' && <CategoriesDialog open onClose={() => setDialog(null)} />}
      <ReasonDialog open={acting !== null} title={t(acting?.kind === 'reject' ? 'ledger.expenses.rejectTitle' : 'ledger.expenses.voidTitle', { no: acting?.expense.expense_no ?? '' })} body={t(acting?.kind === 'reject' ? 'ledger.expenses.rejectBody' : 'ledger.expenses.voidBody')} confirmLabel={t(acting?.kind === 'reject' ? 'ledger.expenses.reject' : 'ledger.expenses.void')} danger={acting?.kind === 'void'} loading={decide.isPending} error={decide.error} onCancel={() => setActing(null)} onConfirm={(r) => decide.mutate(r)} />
    </>
  );
}
