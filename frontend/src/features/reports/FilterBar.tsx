import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Input, Select, Switch } from '@/design/components';
import { useWarehouses } from '@/features/stock/hooks';
import { cn } from '@/lib/cn';
import { VariantPicker } from './VariantPicker';
import { http, type Envelope } from '@/lib/api';
import type { Filters, RangeKey, ReportSummary } from './types';
import { rangeDates } from './util';

const ALL = '__all__';
const RANGES: Exclude<RangeKey, 'custom'>[] = ['today', 'week', 'month', 'last_month'];

/** The filters a report supports (spec reportCommon): date range or as-of date, branch, warehouse, grouping, compare to the previous period. */
export function FilterBar({ def, f, onChange }: { def: ReportSummary; f: Filters; onChange: (patch: Partial<Filters>) => void }) {
  const { t } = useTranslation();
  const kind = def.options.range ?? 'range';
  const warehouses = useWarehouses();
  const branches = useQuery({ queryKey: ['branches', 'pick'], queryFn: () => http.get<Envelope<{ id: number; name: string }[]>>('/branches').then((r) => r.data.data), enabled: Boolean(def.options.branch), staleTime: 5 * 60_000 });
  return (
    <div className="mb-4 flex flex-wrap items-end gap-3" data-testid="report-filters">
      {kind === 'range' && (
        <>
          <div role="group" aria-label={t('reports.filters.range')} className="flex rounded-input border border-navy-200 bg-surface p-0.5">
            {RANGES.map((r) => <button key={r} type="button" aria-pressed={f.range === r} onClick={() => onChange({ range: r, ...rangeDates(r), page: 1 })} className={cn('rounded-[6px] px-3 py-1.5 text-sm font-medium focus-visible:focus-ring-light', f.range === r ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-50')}>{t(`reports.filters.ranges.${r}`)}</button>)}
          </div>
          <div className="w-40"><Input label={t('reports.filters.from')} type="date" value={f.from} onChange={(e) => onChange({ range: 'custom', from: e.target.value, page: 1 })} /></div>
          <div className="w-40"><Input label={t('reports.filters.to')} type="date" value={f.to} onChange={(e) => onChange({ range: 'custom', to: e.target.value, page: 1 })} /></div>
        </>
      )}
      {kind === 'asof' && <div className="w-44"><Input label={t('reports.filters.asOf')} type="date" value={f.asOf} onChange={(e) => onChange({ asOf: e.target.value, page: 1 })} /></div>}
      {def.options.group && def.options.group.length > 0 && (
        <div className="w-48"><Select label={t('reports.filters.group')} value={f.group || def.options.group[0]!} onChange={(v) => onChange({ group: v, page: 1 })} options={def.options.group.map((g) => ({ value: g, label: t(`reports.groups.${g}`, { defaultValue: g }) }))} /></div>
      )}
      {def.options.branch && (branches.data?.length ?? 0) > 1 && (
        <div className="w-48"><Select label={t('reports.filters.branch')} value={f.branchId || ALL} onChange={(v) => onChange({ branchId: v === ALL ? '' : v, page: 1 })} options={[{ value: ALL, label: t('common.all') }, ...(branches.data ?? []).map((b) => ({ value: String(b.id), label: b.name }))]} /></div>
      )}
      {def.options.warehouse && (
        <div className="w-48"><Select label={t('reports.filters.warehouse')} value={f.warehouseId || ALL} onChange={(v) => onChange({ warehouseId: v === ALL ? '' : v, page: 1 })} options={[{ value: ALL, label: t('common.all') }, ...(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))]} /></div>
      )}
      {def.options.variant && <div className="w-72"><VariantPicker value={f.variantId} onChange={(id) => onChange({ variantId: id, page: 1 })} /></div>}
      {def.options.compare && kind === 'range' && <div className="pb-2"><Switch label={t('reports.filters.compare')} checked={f.compare} onChange={(v) => onChange({ compare: v })} /></div>}
    </div>
  );
}
