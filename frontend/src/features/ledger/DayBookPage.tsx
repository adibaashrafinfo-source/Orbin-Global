import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Card, DataTable, EmptyState, ErrorState, Input, KpiCard, PageHeader, Pagination, Select } from '@/design/components';
import { formatMoney } from '@/lib/money';
import { dayBookApi, LEDGER_KEYS } from './api';
import { today, useAccounts } from './hooks';
import { LedgerTabs } from './LedgerTabs';
import type { DayBookRow } from './types';

const ALL = '__all__';
const REF_LABEL: Record<string, string> = { opening: 'opening', sale_payment: 'salePayment', sale_refund: 'saleRefund', party_payment: 'partyPayment', expense: 'expense', fund_transfer: 'fundTransfer', shift_cash_movement: 'shiftCash', adjustment: 'adjustment' };

/** FR-1006: every taka in and out across the accounts for a date range, with each account's opening and closing balance. */
export function DayBookPage() {
  const { t } = useTranslation();
  const accounts = useAccounts();
  const [f, setF] = useState({ from: today(), to: today(), account: '' });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const set = (patch: Partial<typeof f>) => { setF((s) => ({ ...s, ...patch })); setPage(1); };
  const params = { from: f.from, to: f.to, account_id: f.account, page, per_page: perPage };
  const query = useQuery({ queryKey: [...LEDGER_KEYS.dayBook, params], queryFn: () => dayBookApi.get(params), placeholderData: (p) => p, enabled: f.from !== '' && f.to !== '' && f.from <= f.to });
  const columns = useMemo<ColumnDef<DayBookRow, unknown>[]>(() => [
    { id: 'date', header: t('ledger.date'), cell: ({ row }) => <span className="whitespace-nowrap">{row.original.date}</span> },
    { id: 'account', header: t('ledger.account'), cell: ({ row }) => row.original.account_name },
    { id: 'kind', header: t('ledger.entry'), cell: ({ row }) => <Badge tone={row.original.direction === 'in' ? 'success' : 'orange'}>{t(`ledger.refTypes.${REF_LABEL[row.original.ref_type] ?? 'adjustment'}`)}</Badge> },
    { id: 'ref', header: t('ledger.reference'), cell: ({ row }) => row.original.ref_no ?? '—' },
    { id: 'party', header: t('ledger.party'), cell: ({ row }) => row.original.party_name ?? '—' },
    { id: 'in', header: t('ledger.moneyIn'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{row.original.direction === 'in' ? formatMoney(row.original.amount) : ''}</span> },
    { id: 'out', header: t('ledger.moneyOut'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{row.original.direction === 'out' ? formatMoney(row.original.amount) : ''}</span> },
  ], [t]);
  const d = query.data?.data;
  return (
    <>
      <PageHeader title={t('nav.ledger')} description={t('ledger.daybook.subtitle')} crumbs={[{ label: t('nav.ledger') }, { label: t('ledger.tabs.daybook') }]} />
      <LedgerTabs />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-40"><Input label={t('ledger.from')} type="date" max={today()} value={f.from} onChange={(e) => set({ from: e.target.value })} /></div>
        <div className="w-40"><Input label={t('ledger.to')} type="date" max={today()} value={f.to} onChange={(e) => set({ to: e.target.value })} error={f.from > f.to ? t('ledger.daybook.badRange') : undefined} /></div>
        <div className="w-56"><Select label={t('ledger.account')} value={f.account || ALL} onChange={(v) => set({ account: v === ALL ? '' : v })} options={[{ value: ALL, label: t('common.all') }, ...(accounts.data ?? []).map((a) => ({ value: String(a.id), label: a.name }))]} /></div>
      </div>
      {query.isError ? <ErrorState onRetry={() => void query.refetch()} /> : (
        <>
          <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard label={t('ledger.opening')} value={formatMoney(d?.totals.opening ?? '0')} />
            <KpiCard label={t('ledger.moneyIn')} value={formatMoney(d?.totals.in ?? '0')} />
            <KpiCard label={t('ledger.moneyOut')} value={formatMoney(d?.totals.out ?? '0')} />
            <KpiCard label={t('ledger.closing')} value={formatMoney(d?.totals.closing ?? '0')} />
          </div>
          <Card className="mb-5"><div className="overflow-x-auto"><table className="w-full text-sm" data-testid="daybook-accounts">
            <caption className="sr-only">{t('ledger.daybook.perAccount')}</caption>
            <thead><tr className="text-xs text-ink-muted"><th scope="col" className="px-4 py-2 text-start font-medium">{t('ledger.account')}</th><th scope="col" className="text-end font-medium">{t('ledger.opening')}</th><th scope="col" className="text-end font-medium">{t('ledger.moneyIn')}</th><th scope="col" className="text-end font-medium">{t('ledger.moneyOut')}</th><th scope="col" className="px-4 text-end font-medium">{t('ledger.closing')}</th></tr></thead>
            <tbody>{(d?.accounts ?? []).map((a) => <tr key={a.account_id} className="border-t border-navy-100"><td className="px-4 py-2 font-medium">{a.name}</td><td className="tabular text-end">{formatMoney(a.opening)}</td><td className="tabular text-end">{formatMoney(a.in)}</td><td className="tabular text-end">{formatMoney(a.out)}</td><td className="tabular px-4 text-end font-semibold">{formatMoney(a.closing)}</td></tr>)}</tbody>
          </table></div></Card>
          <DataTable columns={columns} data={d?.rows ?? []} loading={query.isLoading} getRowId={(r) => String(r.id)} compact caption={t('ledger.tabs.daybook')} empty={<EmptyState body={t('ledger.daybook.empty')} />} />
          {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
        </>
      )}
    </>
  );
}
