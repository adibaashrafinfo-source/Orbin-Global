import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Download, Eye } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, DataTable, EmptyState, IconButton, Input, KpiCard, PageHeader, Pagination, Select, Switch, toast } from '@/design/components';
import { StopClick } from '@/features/catalog/StopClick';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { SALES_KEYS, saleApi } from '../api';
import { fmtDateTime, SALE_TONE } from '../format';
import { downloadBlob } from '../pos/files';
import { SalesTabs } from '../SalesTabs';
import type { Sale } from '../types';

const ALL = '__all__';

/** FR-713, PRD §8.4: invoices with filters, a summary strip and quick actions. */
export function SalesPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [f, setF] = useState({ search: '', from: '', to: '', status: '', method: '', due: false });
  const q = useDebouncedValue(f.search, 250);
  const params = { page, per_page: perPage, search: q, 'filter[from]': f.from, 'filter[to]': f.to, 'filter[status]': f.status, 'filter[method]': f.method, 'filter[due_only]': f.due };
  const query = useQuery({ queryKey: [...SALES_KEYS.sales, params], queryFn: () => saleApi.list(params), placeholderData: (p) => p });
  const set = (patch: Partial<typeof f>) => { setF((s) => ({ ...s, ...patch })); setPage(1); };
  const save = useCallback(async (s: Sale) => { try { downloadBlob(await saleApi.pdf(s.id), `Invoice_${s.invoice_no}.pdf`); } catch { toast.error(t('errors.unknown')); } }, [t]);
  const columns = useMemo<ColumnDef<Sale, unknown>[]>(() => [
    { id: 'no', header: t('sales.invoice'), cell: ({ row }) => <span className="font-semibold text-navy-900">{row.original.invoice_no}</span> },
    { id: 'date', header: t('sales.date'), cell: ({ row }) => <span className="whitespace-nowrap">{fmtDateTime(row.original.sale_date, i18n.language)}</span> },
    { id: 'customer', header: t('sales.customer'), cell: ({ row }) => row.original.customer_snapshot.name },
    { id: 'cashier', header: t('sales.cashier'), cell: ({ row }) => row.original.cashier ?? '—' },
    { id: 'total', header: t('sales.total'), cell: ({ row }) => <span className="tabular whitespace-nowrap font-semibold">{formatMoney(row.original.grand_total)}</span> },
    { id: 'due', header: t('sales.due'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{Number(row.original.due_total) > 0 ? formatMoney(row.original.due_total) : '—'}</span> },
    { id: 'pay', header: t('sales.payment'), cell: ({ row }) => <Badge tone={SALE_TONE[row.original.payment_status] ?? 'neutral'}>{t(`sales.pay.${row.original.payment_status}`)}</Badge> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={SALE_TONE[row.original.status] ?? 'neutral'}>{t(`sales.status.${row.original.status}`)}</Badge> },
    { id: 'actions', header: () => <span className="sr-only">{t('common.actions')}</span>, cell: ({ row }) => <StopClick className="flex justify-end gap-1"><IconButton label={t('sales.view')} onClick={() => navigate(`/sales/${row.original.id}`)}><Eye className="h-4 w-4" aria-hidden="true" /></IconButton><IconButton label={t('sales.downloadPdf')} onClick={() => void save(row.original)}><Download className="h-4 w-4" aria-hidden="true" /></IconButton></StopClick> },
  ], [t, i18n.language, navigate, save]);
  const s = query.data?.summary;
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.sales')} description={t('sales.subtitle')} crumbs={[{ label: t('nav.sales') }]} />
      <SalesTabs />
      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard label={t('sales.kpiCount')} value={String(s?.count ?? 0)} />
        <KpiCard label={t('sales.kpiTotal')} value={formatMoney(s?.grand_total ?? '0')} />
        <KpiCard label={t('sales.kpiPaid')} value={formatMoney(s?.paid_total ?? '0')} />
        <KpiCard label={t('sales.kpiDue')} value={formatMoney(s?.due_total ?? '0')} />
      </div>
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-56"><Input label={t('common.search')} type="search" placeholder={t('sales.searchHint')} value={f.search} onChange={(e) => set({ search: e.target.value })} /></div>
        <div className="w-40"><Input label={t('sales.from')} type="date" value={f.from} onChange={(e) => set({ from: e.target.value })} /></div>
        <div className="w-40"><Input label={t('sales.to')} type="date" value={f.to} onChange={(e) => set({ to: e.target.value })} /></div>
        <div className="w-44"><Select label={t('common.status')} value={f.status || ALL} onChange={(v) => set({ status: v === ALL ? '' : v })} options={[{ value: ALL, label: t('common.all') }, ...['completed', 'partially_returned', 'returned', 'void'].map((x) => ({ value: x, label: t(`sales.status.${x}`) }))]} /></div>
        <div className="w-44"><Select label={t('sales.method')} value={f.method || ALL} onChange={(v) => set({ method: v === ALL ? '' : v })} options={[{ value: ALL, label: t('common.all') }, ...['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer'].map((x) => ({ value: x, label: t(`pos.pay.methods.${x}`) }))]} /></div>
        <div className="pb-2"><Switch label={t('sales.dueOnly')} checked={f.due} onChange={(v) => set({ due: v })} /></div>
      </div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(r) => String(r.id)} onRowClick={(r) => navigate(`/sales/${r.id}`)} compact caption={t('nav.sales')} empty={<EmptyState body={t('sales.empty')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
    </>
  );
}
