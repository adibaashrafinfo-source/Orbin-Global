import { Banknote, Landmark, Smartphone, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Badge, Card, CardBody, CardHeader, EmptyState } from '@/design/components';
import { fmtDateTime, SALE_TONE } from '@/features/sales/format';
import { cn } from '@/lib/cn';
import { formatMoney, toPaisa } from '@/lib/money';
import type { DashboardData } from './types';

const GLYPH: Record<string, LucideIcon> = { cash: Banknote, bkash: Smartphone, nagad: Smartphone, rocket: Smartphone, bank: Landmark };
const APPROVAL: { key: 'returns' | 'adjustments' | 'expenses' | 'payments'; to: string }[] = [
  { key: 'returns', to: '/sales/returns' }, { key: 'adjustments', to: '/inventory/adjustments' }, { key: 'expenses', to: '/ledger/expenses' }, { key: 'payments', to: '/reports/pending-payments' },
];

/** Row 4: account balances, what waits for a manager, and the latest invoices. */
export function BottomRow({ w }: { w: DashboardData['widgets'] }) {
  const { t, i18n } = useTranslation();
  const balances = w['account-balances'];
  const approvals = w['pending-approvals'];
  const recent = w['recent-sales'];

  return (
    <>
      {balances && (
        <section aria-label={t('dashboard.balances.title')} className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="account-balances">
          {balances.rows.map((a) => {
            const Icon = GLYPH[a.type] ?? Banknote;

            return <Link key={a.id} to="/ledger/accounts" className="card flex items-center gap-3 p-4 focus-visible:focus-ring-light"><span className="rounded-pill bg-navy-100 p-2 text-navy-800"><Icon className="h-4 w-4" aria-hidden="true" /></span><span className="min-w-0"><span className="block truncate text-13 text-ink-muted">{a.name}</span><span className={cn('tabular font-heading text-lg font-bold', toPaisa(a.balance) < 0n ? 'text-danger' : 'text-navy-900')}>{formatMoney(a.balance)}</span></span></Link>;
          })}
        </section>
      )}
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        {approvals && (
          <Card data-testid="pending-approvals"><CardHeader title={t('dashboard.approvals.title')} /><CardBody>
            <ul className="space-y-2 text-sm">{APPROVAL.map((a) => <li key={a.key}><Link to={a.to} className="flex items-center justify-between rounded-input px-2 py-1.5 hover:bg-navy-50 focus-visible:focus-ring-light"><span>{t(`dashboard.approvals.${a.key}`)}</span><Badge tone={approvals[a.key] > 0 ? 'orange' : 'neutral'}>{approvals[a.key]}</Badge></Link></li>)}</ul>
          </CardBody></Card>
        )}
        {recent && (
          <Card data-testid="recent-sales"><CardHeader title={t('dashboard.recent.title')} actions={<Link to="/sales" className="text-sm font-semibold text-navy-800 hover:underline focus-visible:focus-ring-light">{t('dashboard.recent.all')}</Link>} /><CardBody className="px-0 py-0">
            {recent.rows.length === 0 ? <div className="p-5"><EmptyState body={t('dashboard.recent.empty')} /></div> : (
              <div className="relative overflow-x-auto"><table className="w-full text-sm"><caption className="sr-only">{t('dashboard.recent.title')}</caption>
                <thead><tr className="text-xs text-ink-muted"><th scope="col" className="px-5 py-2 text-start font-medium">{t('sales.invoice')}</th><th scope="col" className="text-start font-medium">{t('sales.customer')}</th><th scope="col" className="text-start font-medium">{t('sales.date')}</th><th scope="col" className="text-end font-medium">{t('sales.total')}</th><th scope="col" className="px-5 text-end font-medium">{t('common.status')}</th></tr></thead>
                <tbody>{recent.rows.map((r) => <tr key={r.id} className="border-t border-navy-100"><td className="px-5 py-2"><Link to={`/sales/${r.id}`} className="font-semibold text-navy-800 hover:underline focus-visible:focus-ring-light">{r.invoice_no}</Link></td><td className="max-w-[10rem] truncate">{r.customer}</td><td className="whitespace-nowrap">{fmtDateTime(r.at, i18n.language)}</td><td className="tabular text-end">{formatMoney(r.total)}</td><td className="px-5 text-end"><Badge tone={SALE_TONE[r.status] ?? 'neutral'}>{t(`sales.status.${r.status}`)}</Badge></td></tr>)}</tbody>
              </table></div>
            )}
          </CardBody></Card>
        )}
      </div>
    </>
  );
}
