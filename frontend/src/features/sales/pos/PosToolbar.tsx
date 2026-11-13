import { ScanLine, Search } from 'lucide-react';
import type { RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Select, Switch } from '@/design/components';
import { useLookupOptions, useMasterOptions } from '@/features/catalog/hooks';
import type { GridFilters } from './ProductGrid';

const ALL = '__all__';
const TYPES = ['side_mirror', 'interior_mirror', 'mirror_glass', 'mirror_cover', 'mirror_signal_lamp', 'mirror_motor', 'blind_spot_mirror', 'accessory'];

/** FR-703: the large search box (a scanner types into it and presses Enter) and the quick filters. */
export function PosToolbar({ search, onSearch, onScan, filters, onFilters, searchRef }: { search: string; onSearch: (v: string) => void; onScan: (code: string) => void; filters: GridFilters; onFilters: (f: GridFilters) => void; searchRef: RefObject<HTMLInputElement | null> }) {
  const { t } = useTranslation();
  const brands = useMasterOptions('product-brands', 'name');
  const grades = useLookupOptions('quality_grade');
  const opt = (list: { value: string; label: string }[]) => [{ value: ALL, label: t('common.all') }, ...list];
  return (
    <div className="flex flex-col gap-2">
      <label className="relative block">
        <span className="sr-only">{t('pos.search')}</span>
        <Search className="pointer-events-none absolute start-4 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
        <input ref={searchRef} id="pos-search" type="search" value={search} autoComplete="off" placeholder={t('pos.searchPlaceholder')} data-testid="pos-search"
          onChange={(e) => onSearch(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter' && search.trim() !== '') { e.preventDefault(); onScan(search.trim()); } }}
          className="h-12 w-full rounded-input border border-navy-200 bg-surface ps-12 pe-12 text-base focus:border-navy-600 focus:outline-none focus:shadow-[0_0_0_3px_theme(colors.navy.600/0.15)]" />
        <ScanLine className="pointer-events-none absolute end-4 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
      </label>
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-44"><Select aria-label={t('pos.filterType')} label={t('pos.filterType')} value={filters.mirrorType || ALL} onChange={(v) => onFilters({ ...filters, mirrorType: v === ALL ? '' : v })} options={opt(TYPES.map((x) => ({ value: x, label: t(`products.mirrorTypes.${x}`) })))} /></div>
        <div className="w-40"><Select aria-label={t('pos.filterBrand')} label={t('pos.filterBrand')} value={filters.brandId || ALL} onChange={(v) => onFilters({ ...filters, brandId: v === ALL ? '' : v })} options={opt(brands)} /></div>
        <div className="w-40"><Select aria-label={t('pos.filterQuality')} label={t('pos.filterQuality')} value={filters.qualityId || ALL} onChange={(v) => onFilters({ ...filters, qualityId: v === ALL ? '' : v })} options={opt(grades)} /></div>
        <div className="pb-2"><Switch label={t('pos.inStockOnly')} checked={filters.onlyInStock} onChange={(v) => onFilters({ ...filters, onlyInStock: v })} /></div>
      </div>
    </div>
  );
}
