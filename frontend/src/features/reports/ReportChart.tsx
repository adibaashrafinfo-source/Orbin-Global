import { useReducedMotion } from 'framer-motion';
import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, Cell, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { colors } from '@/design/tokens.generated';
import { formatMoney } from '@/lib/money';
import type { ReportColumn, ReportResult } from './types';
import { labelKey, plain } from './util';

/** The chart a report offers: a navy series with the orange highlight on the latest point (line) or the biggest bar. Brand palette only. */
export function ReportChart({ def }: { def: ReportResult }) {
  const { t } = useTranslation();
  const reduced = useReducedMotion();
  const chart = def.chart;
  if (!chart || def.rows.length === 0) return null;
  const yCol: ReportColumn | undefined = def.columns.find((c) => c.key === chart.y);
  if (!yCol) return null;
  const isMoney = yCol.type === 'money';
  const data = def.rows.slice(0, chart.type === 'bar' ? 12 : 60).map((r) => ({ x: plain(r[chart.x]), y: Number(plain(r[chart.y]) || 0) }));
  const max = Math.max(...data.map((d) => d.y));
  const tip = (v: number) => (isMoney ? formatMoney(v.toFixed(2)) : String(v));
  const label = t(`reports.col.${labelKey(yCol.label)}`, { defaultValue: yCol.label });
  return (
    <div className="card mb-4 p-4" role="img" aria-label={t('reports.chartLabel', { name: label })} data-testid="report-chart">
      <ResponsiveContainer width="100%" height={240}>
        {chart.type === 'line' ? (
          <LineChart data={data} margin={{ top: 8, right: 12, left: 4, bottom: 0 }}>
            <CartesianGrid stroke={colors.navy[100]} vertical={false} />
            <XAxis dataKey="x" tick={{ fontSize: 11, fill: colors.neutral.textMuted }} tickLine={false} axisLine={{ stroke: colors.navy[200] }} />
            <YAxis tick={{ fontSize: 11, fill: colors.neutral.textMuted }} tickLine={false} axisLine={false} width={64} />
            <Tooltip formatter={(v: number) => [tip(v), label]} />
            <Line type="monotone" dataKey="y" stroke={colors.navy[800]} strokeWidth={2} isAnimationActive={!reduced}
              dot={(p: { cx?: number; cy?: number; index?: number }) => <circle key={p.index} cx={p.cx} cy={p.cy} r={p.index === data.length - 1 ? 5 : 3} fill={p.index === data.length - 1 ? colors.orange[500] : colors.navy[800]} />} />
          </LineChart>
        ) : (
          <BarChart data={data} margin={{ top: 8, right: 12, left: 4, bottom: 0 }}>
            <CartesianGrid stroke={colors.navy[100]} vertical={false} />
            <XAxis dataKey="x" tick={{ fontSize: 11, fill: colors.neutral.textMuted }} tickLine={false} axisLine={{ stroke: colors.navy[200] }} interval={0} angle={-20} textAnchor="end" height={56} />
            <YAxis tick={{ fontSize: 11, fill: colors.neutral.textMuted }} tickLine={false} axisLine={false} width={64} />
            <Tooltip formatter={(v: number) => [tip(v), label]} />
            <Bar dataKey="y" radius={[4, 4, 0, 0]} isAnimationActive={!reduced}>{data.map((d, i) => <Cell key={i} fill={d.y === max ? colors.orange[500] : colors.navy[800]} />)}</Bar>
          </BarChart>
        )}
      </ResponsiveContainer>
    </div>
  );
}
