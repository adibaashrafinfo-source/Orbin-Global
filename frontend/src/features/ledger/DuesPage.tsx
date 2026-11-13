import { useQuery } from '@tanstack/react-query';
import { CalendarClock, Copy, FileText, HandCoins } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, Card, EmptyState, ErrorState, IconButton, Input, KpiCard, PageHeader, Pagination, Select, Skeleton, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { COMPANY_KEY, companyApi } from '@/features/settings/company/api';
import { downloadBlob } from '@/features/sales/pos/files';
import { formatMoney } from '@/lib/money';
import { reminderText } from './allocation';
import { duesApi, ledgerApi, LEDGER_KEYS } from './api';
import { LedgerTabs } from './LedgerTabs';
import { PromiseDialog } from './PromiseDialog';
import { BUCKETS, type DuesRow, type PartyType } from './types';

const ALL = '__all__';

/** FR-1007: who owes us and whom we owe, in aging buckets, with a promise-to-pay and a reminder to copy. */
export function DuesPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const [type, setType] = useState<PartyType>('customer');
  const [f, setF] = useState({ search: '', bucket: '', sort: 'balance' });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [promise, setPromise] = useState<DuesRow | null>(null);
  const q = useDebouncedValue(f.search, 250);
  const params = { type, search: q, bucket: f.bucket, sort: f.sort, page, per_page: perPage };
  const query = useQuery({ queryKey: [...LEDGER_KEYS.dues, params], queryFn: () => duesApi.list(params), placeholderData: (p) => p });
  const company = useQuery({ queryKey: COMPANY_KEY, queryFn: companyApi.show, staleTime: 5 * 60_000 });
  const set = (patch: Partial<typeof f>) => { setF((s) => ({ ...s, ...patch })); setPage(1); };
  const canAct = can([type === 'customer' ? 'ledger.receive_payment' : 'ledger.pay_supplier']);
  const copy = async (r: DuesRow) => {
    const text = reminderText({ name: r.name, amount: formatMoney(r.balance), company: company.data?.legal_name ?? '', phone: r.phone, template: t('ledger.dues.reminderTemplate') });
    try { await navigator.clipboard.writeText(text); toast.success(t('ledger.dues.copied')); } catch { toast.error(t('ledger.dues.copyFailed')); }
  };
  const statement = async (r: DuesRow) => { try { downloadBlob(await ledgerApi.statementPdf(r.type, r.id), `Statement_${r.code}.pdf`); } catch { toast.error(t('ledger.statementFailed')); } };
  const s = query.data?.summary;
  const rows = query.data?.data ?? [];
  const payLink = (r: DuesRow) => `/ledger/receive?type=${r.type}&id=${r.id}&code=${encodeURIComponent(r.code)}&name=${encodeURIComponent(r.name)}`;
  return (
    <>
      <PageHeader title={t('nav.ledger')} description={t('ledger.dues.subtitle')} crumbs={[{ label: t('nav.ledger') }, { label: t('ledger.tabs.dues') }]} />
      <LedgerTabs />
      <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
        <KpiCard label={t(type === 'customer' ? 'ledger.dues.totalReceivable' : 'ledger.dues.totalPayable')} value={formatMoney(s?.total ?? '0')} />
        {BUCKETS.map((b) => <KpiCard key={b} label={t(`ledger.dues.buckets.${b}`)} value={formatMoney(s?.buckets[b] ?? '0')} />)}
      </div>
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-64"><Select label={t('ledger.partyKind')} value={type} onChange={(v) => { setType(v as PartyType); setPage(1); }} options={[{ value: 'customer', label: t('ledger.dues.receivable') }, { value: 'supplier', label: t('ledger.dues.payable') }]} /></div>
        <div className="w-56"><Input label={t('common.search')} type="search" value={f.search} onChange={(e) => set({ search: e.target.value })} /></div>
        <div className="w-44"><Select label={t('ledger.dues.bucket')} value={f.bucket || ALL} onChange={(v) => set({ bucket: v === ALL ? '' : v })} options={[{ value: ALL, label: t('common.all') }, ...BUCKETS.map((b) => ({ value: b, label: t(`ledger.dues.buckets.${b}`) }))]} /></div>
        <div className="w-44"><Select label={t('ledger.dues.sort')} value={f.sort} onChange={(v) => set({ sort: v })} options={['balance', 'oldest', 'name'].map((x) => ({ value: x, label: t(`ledger.dues.sorts.${x}`) }))} /></div>
      </div>
      {query.isError ? <ErrorState onRetry={() => void query.refetch()} /> : query.isLoading ? <Skeleton className="h-64" /> : rows.length === 0 ? <EmptyState body={t('ledger.dues.empty')} /> : (
        <Card><div className="overflow-x-auto"><table className="w-full text-sm" data-testid="dues-table">
          <caption className="sr-only">{t('ledger.tabs.dues')}</caption>
          <thead><tr className="text-xs text-ink-muted">
            <th scope="col" className="px-4 py-2 text-start font-medium">{t('ledger.party')}</th>
            <th scope="col" className="text-end font-medium">{t('ledger.balance')}</th>
            {BUCKETS.map((b) => <th key={b} scope="col" className="text-end font-medium">{t(`ledger.dues.buckets.${b}`)}</th>)}
            <th scope="col" className="ps-4 text-start font-medium">{t('ledger.dues.promise')}</th>
            <th scope="col" className="px-4 text-end font-medium"><span className="sr-only">{t('common.actions')}</span></th>
          </tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={`${r.type}-${r.id}`} className="border-t border-navy-100">
                <td className="px-4 py-2"><Link className="font-semibold text-navy-800 hover:underline" to={`/ledger/parties?type=${r.type}&id=${r.id}&code=${encodeURIComponent(r.code)}&name=${encodeURIComponent(r.name)}`}>{r.name}</Link><span className="block text-xs text-ink-muted">{r.code}{r.phone ? ` · ${r.phone}` : ''}</span></td>
                <td className="tabular text-end font-semibold">{formatMoney(r.balance)}</td>
                {BUCKETS.map((b) => <td key={b} className={`tabular text-end ${Number(r.buckets[b]) > 0 ? (b === 'd90_plus' || b === 'd61_90' ? 'font-semibold text-danger' : '') : 'text-ink-muted'}`}>{Number(r.buckets[b]) > 0 ? formatMoney(r.buckets[b], { symbol: false }) : '—'}</td>)}
                <td className="ps-4">{r.promise_date ? <span><Badge tone={r.promise_date < new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date()) ? 'danger' : 'navy'}>{r.promise_date}</Badge>{r.promise_note && <span className="block max-w-[14rem] truncate text-xs text-ink-muted" title={r.promise_note}>{r.promise_note}</span>}</span> : <span className="text-ink-muted">—</span>}</td>
                <td className="px-4"><span className="flex justify-end gap-1">
                  {canAct && <IconButton label={t('ledger.dues.setPromise')} onClick={() => setPromise(r)}><CalendarClock className="h-4 w-4" aria-hidden="true" /></IconButton>}
                  {canAct && <Link to={payLink(r)} aria-label={t(type === 'customer' ? 'ledger.receivePayment' : 'ledger.makePayment')} className="inline-flex h-9 w-9 items-center justify-center rounded-button text-navy-800 hover:bg-navy-100 focus-visible:focus-ring-light"><HandCoins className="h-4 w-4" aria-hidden="true" /></Link>}
                  {type === 'customer' && <IconButton label={t('ledger.dues.copyReminder')} onClick={() => void copy(r)}><Copy className="h-4 w-4" aria-hidden="true" /></IconButton>}
                  <IconButton label={t('ledger.downloadStatement')} onClick={() => void statement(r)}><FileText className="h-4 w-4" aria-hidden="true" /></IconButton>
                </span></td>
              </tr>
            ))}
          </tbody>
        </table></div></Card>
      )}
      {query.data && rows.length > 0 && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      {promise && <PromiseDialog key={`${promise.type}-${promise.id}`} row={promise} onClose={() => setPromise(null)} />}
    </>
  );
}
