import { X } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Select } from '@/design/components';
import { useMaster } from '@/features/catalog/hooks';
import { str } from '@/features/catalog/util';
import { cn } from '@/lib/cn';
import { EMPTY_FINDER, type Finder } from './helpers';

const NONE = '__none__';
const POSITIONS = ['left', 'right', 'interior', 'pair'] as const;

/** FR-702: Make → Model → Year → Side. Each choice narrows the grid to variants that fit that car. */
export function VehicleFinder({ value, onChange, driverSide }: { value: Finder; onChange: (f: Finder) => void; driverSide: 'left' | 'right' }) {
  const { t } = useTranslation();
  const makes = useMaster('vehicle-makes');
  const models = useMaster('vehicle-models');
  const thisYear = new Date().getFullYear() + 1;
  const years = useMemo(() => Array.from({ length: thisYear - 1989 }, (_, i) => String(thisYear - i)), [thisYear]);
  const makeName = (id: string) => str(makes.data?.find((m) => String(m.id) === id)?.name);
  const modelName = (id: string) => str(models.data?.find((m) => String(m.id) === id)?.name);
  const chips: { key: keyof Finder; label: string }[] = [
    ...(value.make ? [{ key: 'make' as const, label: makeName(value.make) }] : []),
    ...(value.model ? [{ key: 'model' as const, label: modelName(value.model) }] : []),
    ...(value.year ? [{ key: 'year' as const, label: value.year }] : []),
    ...(value.position ? [{ key: 'position' as const, label: t(`pos.positions.${value.position}`) }] : []),
  ];
  const modelOptions = (models.data ?? []).filter((m) => m.is_active && (!value.make || String(m.make_id) === value.make));
  const clear = (key: keyof Finder) => onChange({ ...value, [key]: '', ...(key === 'make' ? { model: '' } : {}) });
  return (
    <section aria-label={t('pos.finder')} className="rounded-card border border-navy-100 bg-surface p-3 shadow-sm">
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-40">
          <Select aria-label={t('pos.make')} label={t('pos.make')} value={value.make || NONE} onChange={(v) => onChange({ ...value, make: v === NONE ? '' : v, model: '' })}
            options={[{ value: NONE, label: t('pos.any') }, ...(makes.data ?? []).filter((m) => m.is_active).map((m) => ({ value: String(m.id), label: str(m.name) }))]} />
        </div>
        <div className="w-44">
          <Select aria-label={t('pos.model')} label={t('pos.model')} value={value.model || NONE} onChange={(v) => onChange({ ...value, model: v === NONE ? '' : v })}
            options={[{ value: NONE, label: t('pos.any') }, ...modelOptions.map((m) => ({ value: String(m.id), label: str(m.name) }))]} />
        </div>
        <div className="w-28">
          <Select aria-label={t('pos.year')} label={t('pos.year')} value={value.year || NONE} onChange={(v) => onChange({ ...value, year: v === NONE ? '' : v })} options={[{ value: NONE, label: t('pos.any') }, ...years.map((y) => ({ value: y, label: y }))]} />
        </div>
        <div>
          <div className="mb-1.5 text-sm font-medium text-ink">{t('pos.position')}</div>
          <div role="group" aria-label={t('pos.position')} className="flex gap-1">
            {POSITIONS.map((p) => (
              <button key={p} type="button" aria-pressed={value.position === p} onClick={() => onChange({ ...value, position: value.position === p ? '' : p })}
                className={cn('h-10 rounded-pill border px-3 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light', value.position === p ? 'border-navy-900 bg-navy-900 text-surface' : 'border-navy-200 bg-surface text-navy-800 hover:bg-navy-50')}>
                {t(`pos.positions.${p}`)}{p === driverSide ? <span className="ms-1 text-xs opacity-70">{t('pos.driverSide')}</span> : null}
              </button>
            ))}
          </div>
        </div>
        {chips.length > 0 && <Button variant="ghost" size="sm" onClick={() => onChange(EMPTY_FINDER)}>{t('pos.clearFinder')}</Button>}
      </div>
      {chips.length > 0 && (
        <ul className="mt-2 flex flex-wrap gap-1.5" aria-label={t('pos.finderChips')}>
          {chips.map((c) => (
            <li key={c.key}>
              <span className="inline-flex items-center gap-1 rounded-pill bg-navy-100 py-0.5 ps-2.5 pe-1 text-xs font-semibold text-navy-900">
                {c.label}
                <button type="button" aria-label={t('pos.removeFilter', { name: c.label })} onClick={() => clear(c.key)} className="rounded-pill p-0.5 hover:bg-navy-200 focus-visible:focus-ring-light"><X className="h-3 w-3" aria-hidden="true" /></button>
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
