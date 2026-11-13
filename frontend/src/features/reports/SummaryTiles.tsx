import { useTranslation } from 'react-i18next';
import { KpiCard } from '@/design/components';
import { formatMoney } from '@/lib/money';
import type { ReportColumn, ReportResult } from './types';
import { labelKey } from './util';

function show(c: ReportColumn, v: string): string {
  if (c.type === 'money') return formatMoney(v);
  if (c.type === 'qty') return String(Number(v));

  return v;
}

/** The report's totals as tiles. With "compare" on, each tile shows the change against the previous period. */
export function SummaryTiles({ def }: { def: ReportResult }) {
  const { t } = useTranslation();
  // Up to six tiles, in column order; a total of zero (no delivery charges, no VAT) gives way to one that has something to say.
  const all = def.columns.filter((c) => c.sum && def.totals[c.key] !== undefined);
  const filled = all.filter((c) => Number(def.totals[c.key]) !== 0);
  const tiles = (filled.length >= 1 ? filled : all).slice(0, 6);
  if (tiles.length === 0) return null;
  return (
    <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6" data-testid="report-tiles">
      {tiles.map((c) => {
        const d = def.compare?.delta[c.key];
        const up = d ? !d.change.startsWith('-') : true;
        const range = { from: def.compare?.from, to: def.compare?.to };
        // No percentage exists when the previous period was zero; say so instead of hiding the comparison.
        const text = !d ? null : d.percent !== null ? t('reports.vsPrevious', { percent: d.percent.replace('-', ''), ...range }) : Number(d.change) !== 0 ? t('reports.vsPreviousZero', range) : null;
        return <KpiCard key={c.key} label={t(`reports.col.${labelKey(c.label)}`, { defaultValue: c.label })} value={show(c, def.totals[c.key]!)}
          delta={text ? { value: text, positive: up } : undefined} />;
      })}
    </div>
  );
}
