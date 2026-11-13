import { useTranslation } from 'react-i18next';
import { Input } from '@/design/components';
import { cn } from '@/lib/cn';
import type { Range } from './types';

const RANGES: Range[] = ['today', '7d', '30d', 'month', 'custom'];

/** PRD §8.2: Today · 7D · 30D · Month · Custom. */
export function RangeControl({ range, from, to, onChange }: { range: Range; from: string; to: string; onChange: (r: Range, from?: string, to?: string) => void }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-wrap items-end gap-3">
      <div role="group" aria-label={t('dashboard.range.label')} className="flex rounded-input border border-navy-200 bg-surface p-0.5">
        {RANGES.map((r) => <button key={r} type="button" aria-pressed={range === r} onClick={() => onChange(r, from, to)} data-testid={`range-${r}`} className={cn('rounded-[6px] px-3 py-1.5 text-sm font-medium focus-visible:focus-ring-light', range === r ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-50')}>{t(`dashboard.range.${r}`)}</button>)}
      </div>
      {range === 'custom' && (
        <>
          <div className="w-40"><Input label={t('reports.filters.from')} type="date" value={from} onChange={(e) => onChange('custom', e.target.value, to)} /></div>
          <div className="w-40"><Input label={t('reports.filters.to')} type="date" value={to} onChange={(e) => onChange('custom', from, e.target.value)} /></div>
        </>
      )}
    </div>
  );
}
