import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, DataTable, EmptyState, Input, PageHeader, Pagination, Select } from '@/design/components';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { quotationApi, SALES_KEYS } from '../api';
import { fmtDateTime, SALE_TONE } from '../format';
import { SalesTabs } from '../SalesTabs';
import type { Quotation } from '../types';

const ALL = '__all__';

/** FR-708: quotations made from the POS cart. They hold no stock; converting one hands the cart back to the POS. */
export function QuotationsPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const q = useDebouncedValue(search, 250);
  const params = { page, per_page: perPage, search: q, 'filter[status]': status };
  const query = useQuery({ queryKey: [...SALES_KEYS.quotations, params], queryFn: () => quotationApi.list(params), placeholderData: (p) => p });
  const columns = useMemo<ColumnDef<Quotation, unknown>[]>(() => [
    { id: 'no', header: t('quotations.no'), cell: ({ row }) => <span className="font-semibold text-navy-900">{row.original.quote_no}</span> },
    { id: 'customer', header: t('sales.customer'), cell: ({ row }) => row.original.customer_snapshot.name },
    { id: 'date', header: t('sales.date'), cell: ({ row }) => fmtDateTime(row.original.quote_date, i18n.language, false) },
    { id: 'valid', header: t('quotations.validUntil'), cell: ({ row }) => fmtDateTime(row.original.valid_until, i18n.language, false) },
    { id: 'total', header: t('sales.total'), cell: ({ row }) => <span className="tabular whitespace-nowrap font-semibold">{formatMoney(row.original.grand_total)}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={SALE_TONE[row.original.status] ?? 'neutral'}>{t(`quotations.status.${row.original.status}`)}</Badge> },
  ], [t, i18n.language]);
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.sales')} description={t('quotations.subtitle')} crumbs={[{ label: t('nav.sales'), to: '/sales' }, { label: t('sales.tabs.quotations') }]} />
      <SalesTabs />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-64"><Input label={t('common.search')} type="search" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} /></div>
        <div className="w-44"><Select label={t('common.status')} value={status || ALL} onChange={(v) => { setStatus(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, ...['draft', 'sent', 'accepted', 'expired', 'converted', 'cancelled'].map((x) => ({ value: x, label: t(`quotations.status.${x}`) }))]} /></div>
      </div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(r) => String(r.id)} onRowClick={(r) => navigate(`/quotations/${r.id}`)} compact caption={t('sales.tabs.quotations')} empty={<EmptyState body={t('quotations.empty')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
    </>
  );
}
