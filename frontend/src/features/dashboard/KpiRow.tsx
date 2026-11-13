import { Line, LineChart, ResponsiveContainer } from 'recharts';
import { useReducedMotion } from 'framer-motion';
import { useTranslation } from 'react-i18next';
import { KpiCard } from '@/design/components';
import { colors } from '@/design/tokens.generated';
import { formatMoney } from '@/lib/money';
import type { DashboardData, Delta } from './types';

const money = (n: number) => formatMoney(n.toFixed(2));

function deltaProp(d: Delta, vs: string) {
  return d.percent === null ? undefined : { value: `${d.percent.replace('-', '')}% ${vs}`, positive: !d.change.startsWith('-') };
}

function Spark({ points }: { points: { total: string }[] }) {
  const reduced = useReducedMotion();

  return (
    <div className="h-8 w-full" aria-hidden="true">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={points.map((p) => ({ y: Number(p.total) }))}><Line type="monotone" dataKey="y" stroke={colors.orange[500]} strokeWidth={2} dot={false} isAnimationActive={!reduced} /></LineChart>
      </ResponsiveContainer>
    </div>
  );
}

/** Row 1 of the dashboard: sales, orders, gross profit, collections and dues. Each figure comes from the same report the report page runs. */
export function KpiRow({ w, previousLabel }: { w: DashboardData['widgets']; previousLabel: string }) {
  const { t } = useTranslation();
  const sales = w['kpi-sales'];
  const orders = w['kpi-orders'];
  const profit = w['kpi-profit'];
  const collections = w['kpi-collections'];
  const dues = w['kpi-dues'];
  if (!sales && !orders && !profit && !collections && !dues) return null;

  return (
    <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5" data-testid="kpi-row">
      {sales && <div data-testid="kpi-sales"><KpiCard label={t('dashboard.kpi.sales')} value={formatMoney(sales.value)} numeric={Number(sales.value)} formatter={money} delta={deltaProp(sales.delta, previousLabel)} footer={<Spark points={sales.spark} />} /></div>}
      {orders && <div data-testid="kpi-orders"><KpiCard label={t('dashboard.kpi.orders')} value={String(orders.value)} numeric={Number(orders.value)} delta={deltaProp(orders.delta, previousLabel)} footer={<span className="text-xs text-ink-muted">{t('dashboard.kpi.avgTicket', { amount: formatMoney(orders.avg_ticket) })}</span>} /></div>}
      {profit && <div data-testid="kpi-profit"><KpiCard label={t('dashboard.kpi.profit')} value={formatMoney(profit.value)} numeric={Number(profit.value)} formatter={money} delta={deltaProp(profit.delta, previousLabel)} footer={profit.margin ? <span className="text-xs text-ink-muted">{t('dashboard.kpi.margin', { percent: profit.margin })}</span> : undefined} /></div>}
      {collections && <div data-testid="kpi-collections"><KpiCard label={t('dashboard.kpi.collections')} value={formatMoney(collections.value)} numeric={Number(collections.value)} formatter={money} delta={deltaProp(collections.delta, previousLabel)} /></div>}
      {dues && <div data-testid="kpi-dues"><KpiCard label={t('dashboard.kpi.dues')} value={formatMoney(dues.receivable)} numeric={Number(dues.receivable)} formatter={money} footer={<span className="text-xs text-ink-muted">{t('dashboard.kpi.payable', { amount: formatMoney(dues.payable) })}</span>} /></div>}
    </div>
  );
}
