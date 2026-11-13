import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Badge, Button, DataTable, EmptyState, Input, KpiCard, Modal, PageHeader, Pagination, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { ApiError } from '@/lib/api';
import { formatMoney, toPaisa } from '@/lib/money';
import { posApi, SALES_KEYS, shiftApi } from '../api';
import { fmtDateTime } from '../format';
import { SalesTabs } from '../SalesTabs';
import type { Shift } from '../types';
import { ShiftActions } from './ShiftActions';

/** FR-716, PRD §8.12: today's drawer (cash in and out, close with a count) and the history of closed shifts with their Z-reports. */
export function ShiftsPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { can } = useSession();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const current = useQuery({ queryKey: SALES_KEYS.shift, queryFn: shiftApi.current, refetchInterval: 30_000 });
  const list = useQuery({ queryKey: [...SALES_KEYS.shifts, page, perPage], queryFn: () => shiftApi.list({ page, per_page: perPage }), placeholderData: (p) => p });
  const columns = useMemo<ColumnDef<Shift, unknown>[]>(() => [
    { id: 'no', header: t('shifts.no'), cell: ({ row }) => <span className="font-semibold text-navy-900">{row.original.shift_no}</span> },
    { id: 'cashier', header: t('sales.cashier'), cell: ({ row }) => row.original.cashier ?? '—' },
    { id: 'opened', header: t('shifts.opened'), cell: ({ row }) => <span className="whitespace-nowrap">{fmtDateTime(row.original.opened_at, i18n.language)}</span> },
    { id: 'closed', header: t('shifts.closed'), cell: ({ row }) => <span className="whitespace-nowrap">{fmtDateTime(row.original.closed_at, i18n.language)}</span> },
    { id: 'expected', header: t('shifts.expected'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{row.original.expected_cash ? formatMoney(row.original.expected_cash) : '—'}</span> },
    { id: 'variance', header: t('shifts.variance'), cell: ({ row }) => { const v = row.original.variance; return v === null ? '—' : <span className={`tabular whitespace-nowrap font-semibold ${toPaisa(v) === 0n ? 'text-success' : 'text-danger'}`}>{formatMoney(v)}</span>; } },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={row.original.status === 'open' ? 'orange' : 'neutral'}>{t(`shifts.status.${row.original.status}`)}</Badge> },
  ], [t, i18n.language]);
  const err = list.error ? { message: list.error instanceof ApiError ? t(`errors.${list.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  const c = current.data;
  return (
    <>
      <PageHeader title={t('nav.sales')} description={t('shifts.subtitle')} crumbs={[{ label: t('nav.sales'), to: '/sales' }, { label: t('sales.tabs.shifts') }]} />
      <SalesTabs />
      {c && (
        <section aria-label={t('shifts.current')} className="mb-6 rounded-card border border-navy-100 bg-surface p-4 shadow-sm" data-testid="current-shift">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2"><div><h2 className="font-heading text-base font-bold text-navy-900">{t('shifts.current')} · {c.shift_no}</h2><p className="text-xs text-ink-muted">{t('shifts.since', { time: fmtDateTime(c.opened_at, i18n.language) })}</p></div><ShiftActions shift={c} /></div>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard label={t('shifts.openingFloat')} value={formatMoney(c.totals?.opening_float ?? c.opening_float)} />
            <KpiCard label={t('shifts.cashSales')} value={formatMoney(c.totals?.cash_sales ?? '0')} />
            <KpiCard label={t('shifts.salesCount')} value={String(c.totals?.sales_count ?? 0)} />
            <KpiCard label={t('shifts.expected')} value={formatMoney(c.totals?.expected_cash ?? '0')} />
          </div>
        </section>
      )}
      {!c && can(['shifts.open_close_own']) && <OpenShift />}
      <DataTable columns={columns} data={list.data?.data ?? []} loading={list.isLoading} error={err} onRetry={() => void list.refetch()} getRowId={(r) => String(r.id)} onRowClick={(r) => navigate(`/shifts/${r.id}`)} compact caption={t('sales.tabs.shifts')} empty={<EmptyState body={t('shifts.empty')} />} />
      {list.data && <Pagination page={page} perPage={perPage} total={list.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
    </>
  );
}

function OpenShift() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const config = useQuery({ queryKey: SALES_KEYS.config, queryFn: posApi.config, staleTime: 60_000 });
  const [open, setOpen] = useState(false);
  const [float, setFloat] = useState('');
  const cash = config.data?.accounts.find((a) => a.account_type === 'cash');
  const run = useMutation({ mutationFn: () => shiftApi.open({ cash_account_id: cash?.id ?? 0, opening_float: float || '0' }), onSuccess: () => { void client.invalidateQueries({ queryKey: SALES_KEYS.shift }); void client.invalidateQueries({ queryKey: SALES_KEYS.shifts }); void client.invalidateQueries({ queryKey: SALES_KEYS.config }); toast.success(t('shifts.openedToast')); setOpen(false); }, onError });
  return (
    <div className="mb-6">
      <Button onClick={() => setOpen(true)} disabled={!cash} data-testid="open-shift">{t('shifts.openAction')}</Button>
      <Modal open={open} onOpenChange={setOpen} className="max-w-sm" title={t('shifts.openTitle')} footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t('common.cancel')}</Button><Button type="submit" form="open-shift-form" loading={run.isPending}>{t('shifts.openAction')}</Button></>}>
        <form id="open-shift-form" noValidate onSubmit={(e) => { e.preventDefault(); run.mutate(); }}><Input label={t('shifts.openingFloat')} inputMode="decimal" suffix="৳" value={float} onChange={(e) => setFloat(e.target.value.replace(/[^\d.]/g, ''))} hint={t('shifts.floatHint')} error={serverFieldError(t, run.error, 'opening_float')} /></form>
      </Modal>
    </div>
  );
}
