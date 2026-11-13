import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Check, FileText, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Badge, DataTable, EmptyState, IconButton, PageHeader, Pagination, Select, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { StopClick } from '@/features/catalog/StopClick';
import { useApiError } from '@/features/catalog/useApiError';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { fmtDateTime, SALE_TONE } from '../format';
import { returnApi, SALES_KEYS } from '../api';
import { SalesTabs } from '../SalesTabs';
import type { SaleReturn } from '../types';

const ALL = '__all__';

/** FR-715: returns waiting for approval and the ones already posted, each with its credit note. */
export function SaleReturnsPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const navigate = useNavigate();
  const client = useQueryClient();
  const onError = useApiError();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [status, setStatus] = useState('');
  const params = { page, per_page: perPage, 'filter[status]': status };
  const query = useQuery({ queryKey: [...SALES_KEYS.returns, params], queryFn: () => returnApi.list(params), placeholderData: (p) => p });
  const done = () => { for (const k of [SALES_KEYS.returns, SALES_KEYS.sales, ['inventory'], ['pos', 'variants']]) void client.invalidateQueries({ queryKey: k }); };
  const approve = useMutation({ mutationFn: (r: SaleReturn) => returnApi.approve(r.id), onSuccess: () => { done(); toast.success(t('sales.approved')); }, onError });
  const cancel = useMutation({ mutationFn: (r: SaleReturn) => returnApi.cancel(r.id), onSuccess: done, onError });
  const canApprove = can(['sales.return.approve']);
  const columns = useMemo<ColumnDef<SaleReturn, unknown>[]>(() => [
    { id: 'no', header: t('sales.returnNo'), cell: ({ row }) => <span className="font-semibold text-navy-900">{row.original.return_no}</span> },
    { id: 'invoice', header: t('sales.invoice'), cell: ({ row }) => row.original.invoice_no ?? '—' },
    { id: 'date', header: t('sales.date'), cell: ({ row }) => <span className="whitespace-nowrap">{fmtDateTime(row.original.return_date, i18n.language)}</span> },
    { id: 'refund', header: t('sales.refunded'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{formatMoney(row.original.refund_total)}</span> },
    { id: 'credit', header: t('sales.credited'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{formatMoney(row.original.ledger_credit_total)}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={SALE_TONE[row.original.status] ?? 'neutral'}>{t(`sales.returnStatus.${row.original.status}`)}</Badge> },
    { id: 'actions', header: () => <span className="sr-only">{t('common.actions')}</span>, cell: ({ row }) => (
      <StopClick className="flex justify-end gap-1">
        {row.original.status === 'pending_approval' && canApprove && <><IconButton label={t('sales.approve')} onClick={() => approve.mutate(row.original)}><Check className="h-4 w-4" aria-hidden="true" /></IconButton><IconButton label={t('sales.rejectReturn')} onClick={() => cancel.mutate(row.original)}><X className="h-4 w-4" aria-hidden="true" /></IconButton></>}
        {row.original.status === 'posted' && <a aria-label={t('sales.creditNote')} href={returnApi.pdfUrl(row.original.id)} target="_blank" rel="noopener noreferrer" className="inline-flex h-8 w-8 items-center justify-center rounded-input text-navy-700 hover:bg-navy-100 focus-visible:focus-ring-light"><FileText className="h-4 w-4" aria-hidden="true" /></a>}
      </StopClick>) },
  ], [t, i18n.language, canApprove, approve, cancel]);
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.sales')} description={t('sales.returnsSubtitle')} crumbs={[{ label: t('nav.sales'), to: '/sales' }, { label: t('sales.tabs.returns') }]} />
      <SalesTabs />
      <div className="mb-4 w-48"><Select label={t('common.status')} value={status || ALL} onChange={(v) => { setStatus(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, ...['pending_approval', 'posted', 'cancelled'].map((x) => ({ value: x, label: t(`sales.returnStatus.${x}`) }))]} /></div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(r) => String(r.id)} onRowClick={(r) => navigate(`/sales/returns/${r.id}`)} compact caption={t('sales.tabs.returns')} empty={<EmptyState body={t('sales.returnsEmpty')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
    </>
  );
}
