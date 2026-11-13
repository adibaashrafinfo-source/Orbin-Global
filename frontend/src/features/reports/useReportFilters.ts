import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import type { Filters, RangeKey } from './types';
import { defaultFilters, rangeDates } from './util';

const RANGES: RangeKey[] = ['today', 'week', 'month', 'last_month', 'custom'];

/** Report filters live in the address bar, so a report can be bookmarked, shared and survives a refresh. */
export function useReportFilters(): [Filters, (patch: Partial<Filters>) => void] {
  const [params, setParams] = useSearchParams();
  const f = useMemo<Filters>(() => {
    const d = defaultFilters();
    const range = (RANGES.includes(params.get('range') as RangeKey) ? params.get('range') : d.range) as RangeKey;
    const quick = range === 'custom' ? null : rangeDates(range);

    return {
      range, from: params.get('from') ?? quick?.from ?? d.from, to: params.get('to') ?? quick?.to ?? d.to, asOf: params.get('as_of') ?? d.asOf, branchId: params.get('branch') ?? '', warehouseId: params.get('warehouse') ?? '',
      group: params.get('group') ?? '', variantId: params.get('variant') ?? '', compare: params.get('compare') === '1', sort: params.get('sort') ?? '', dir: params.get('dir') === 'desc' ? 'desc' : 'asc', page: Math.max(1, Number(params.get('page') ?? 1) || 1),
    };
  }, [params]);
  const set = useCallback((patch: Partial<Filters>) => {
    const next = { ...f, ...patch };
    const q = new URLSearchParams();
    q.set('range', next.range);
    q.set('from', next.from);
    q.set('to', next.to);
    q.set('as_of', next.asOf);
    for (const [k, v] of [['branch', next.branchId], ['warehouse', next.warehouseId], ['group', next.group], ['variant', next.variantId], ['sort', next.sort]] as const) if (v) q.set(k, v);
    if (next.sort) q.set('dir', next.dir);
    if (next.compare) q.set('compare', '1');
    if (next.page > 1) q.set('page', String(next.page));
    setParams(q, { replace: true });
  }, [f, setParams]);

  return [f, set];
}
