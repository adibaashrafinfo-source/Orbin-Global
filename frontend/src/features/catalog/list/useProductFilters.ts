import { useState } from 'react';

export const FILTER_KEYS = ['mirror_type', 'category_id', 'brand_id', 'quality_grade_id', 'glass_type_id', 'position', 'make_id', 'model_id', 'is_active', 'stock'] as const;
export type FilterKey = (typeof FILTER_KEYS)[number];
export type Filters = Record<FilterKey, string>;

export const EMPTY_FILTERS: Filters = { mirror_type: '', category_id: '', brand_id: '', quality_grade_id: '', glass_type_id: '', position: '', make_id: '', model_id: '', is_active: '', stock: '' };

const VIEW_KEY = 'orbin.products.view';

export type ViewMode = 'grid' | 'table';

/** Grid/table preference survives reloads; storage failures fall back to the grid. */
export function useViewMode(): [ViewMode, (v: ViewMode) => void] {
  const [mode, setMode] = useState<ViewMode>(() => {
    try {
      return localStorage.getItem(VIEW_KEY) === 'table' ? 'table' : 'grid';
    } catch {
      return 'grid';
    }
  });
  return [
    mode,
    (v) => {
      setMode(v);
      try {
        localStorage.setItem(VIEW_KEY, v);
      } catch {
        /* ignore */
      }
    },
  ];
}

export const activeFilterCount = (f: Filters): number => FILTER_KEYS.filter((k) => f[k] !== '').length;
