import { useReducedMotion } from 'framer-motion';
import { useTranslation } from 'react-i18next';
import { Area, AreaChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Card, CardBody, CardHeader, EmptyState } from '@/design/components';
import { colors } from '@/design/tokens.generated';
import { formatMoney } from '@/lib/money';
import type { DashboardData } from './types';

const SLICES = [colors.navy[900], colors.orange[500], colors.navy[600], colors.navy[300], colors.orange[300], colors.navy[500]];

/** Row 2: the sales trend (navy line, today in orange) and where the money came in (navy and orange donut). */
export function TrendRow({ w }: { w: DashboardData['widgets'] }) {
  const { t } = useTranslation();
  const reduced = useReducedMotion();
  const trend = w['sales-trend'];
  const donut = w['payment-method-donut'];
  if (!trend && !donut) return null;
  const points = (trend?.points ?? []).map((p) => ({ date: p.date.slice(5), full: p.date, total: Number(p.total) }));
  const slices = (donut?.slices ?? []).map((s) => ({ name: t(`pos.pay.methods.${s.method === 'Bank' ? 'bank_transfer' : s.method.toLowerCase()}`, { defaultValue: s.method }), value: Number(s.amount) })).filter((s) => s.value > 0);

  return (
    <div className="mb-5 grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      {trend && (
        <Card data-testid="sales-trend"><CardHeader title={t('dashboard.trend.title')} description={t('dashboard.trend.subtitle')} /><CardBody>
          <div role="img" aria-label={t('dashboard.trend.title')} className="h-60">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={points} margin={{ top: 8, right: 8, left: 4, bottom: 0 }}>
                <defs><linearGradient id="trend-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={colors.navy[600]} stopOpacity={0.25} /><stop offset="100%" stopColor={colors.navy[600]} stopOpacity={0} /></linearGradient></defs>
                <CartesianGrid stroke={colors.navy[100]} vertical={false} />
                <XAxis dataKey="date" tick={{ fontSize: 11, fill: colors.neutral.textMuted }} tickLine={false} axisLine={{ stroke: colors.navy[200] }} />
                <YAxis tick={{ fontSize: 11, fill: colors.neutral.textMuted }} tickLine={false} axisLine={false} width={56} />
                <Tooltip formatter={(v: number) => [formatMoney(v.toFixed(2)), t('dashboard.kpi.sales')]} labelFormatter={(_, p) => (p[0]?.payload as { full: string } | undefined)?.full ?? ''} />
                <Area type="monotone" dataKey="total" stroke={colors.navy[800]} strokeWidth={2} fill="url(#trend-fill)" isAnimationActive={!reduced}
                  dot={(p: { cx?: number; cy?: number; index?: number; payload?: { full: string } }) => (p.payload?.full === trend.highlight ? <circle key={p.index} cx={p.cx} cy={p.cy} r={5} fill={colors.orange[500]} /> : <g key={p.index} />)} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </CardBody></Card>
      )}
      {donut && (
        <Card data-testid="payment-donut"><CardHeader title={t('dashboard.donut.title')} /><CardBody>
          {slices.length === 0 ? <EmptyState body={t('dashboard.donut.empty')} /> : (
            <>
              <div role="img" aria-label={t('dashboard.donut.title')} className="h-40">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart><Pie data={slices} dataKey="value" nameKey="name" innerRadius={42} outerRadius={64} paddingAngle={2} isAnimationActive={!reduced}>{slices.map((_, i) => <Cell key={i} fill={SLICES[i % SLICES.length]} />)}</Pie><Tooltip formatter={(v: number) => formatMoney(v.toFixed(2))} /></PieChart>
                </ResponsiveContainer>
              </div>
              <ul className="mt-2 space-y-1 text-sm">
                {slices.map((s, i) => <li key={s.name} className="flex items-center justify-between gap-2"><span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-pill" style={{ backgroundColor: SLICES[i % SLICES.length] }} aria-hidden="true" />{s.name}</span><span className="tabular font-medium">{formatMoney(s.value.toFixed(2))}</span></li>)}
              </ul>
            </>
          )}
        </CardBody></Card>
      )}
    </div>
  );
}
