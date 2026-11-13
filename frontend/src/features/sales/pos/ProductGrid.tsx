import { useInfiniteQuery } from '@tanstack/react-query';
import { useCallback, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyState, ErrorState, Skeleton } from '@/design/components';
import { posApi } from '../api';
import type { PosCard, PosVariant } from '../types';
import type { Finder } from './helpers';
import { ProductCard } from './ProductCard';

export interface GridFilters {
  text: string;
  onlyInStock: boolean;
  mirrorType: string;
  brandId: string;
  qualityId: string;
}

const PAGE = 24;

/** FR-701/703: the results grid with infinite scroll. Each page asks for 24 products; the next loads as the last row scrolls into view. */
export function ProductGrid({ finder, filters, warehouseId, onAdd, onOpen }: { finder: Finder; filters: GridFilters; warehouseId: number | undefined; onAdd: (card: PosCard, v: PosVariant) => void; onOpen: (card: PosCard) => void }) {
  const { t } = useTranslation();
  const params = { make_id: finder.make, model_id: finder.model, year: finder.year, position: finder.position, text: filters.text, only_in_stock: filters.onlyInStock ? 1 : '', mirror_type: filters.mirrorType, brand_id: filters.brandId, quality_grade_id: filters.qualityId, warehouse_id: warehouseId };
  const query = useInfiniteQuery({
    queryKey: ['pos', 'variants', params],
    queryFn: ({ pageParam }) => posApi.variants({ ...params, limit: PAGE, offset: pageParam }),
    initialPageParam: 0,
    getNextPageParam: (last, all) => (all.length * PAGE < last.meta.total ? all.length * PAGE : undefined),
    enabled: warehouseId !== undefined,
    staleTime: 15_000,
    refetchInterval: 45_000,
  });
  const observer = useRef<IntersectionObserver | null>(null);
  const sentinel = useCallback(
    (node: HTMLDivElement | null) => {
      observer.current?.disconnect();
      if (!node) return;
      observer.current = new IntersectionObserver((e) => {
        if (e[0]?.isIntersecting && query.hasNextPage && !query.isFetchingNextPage) void query.fetchNextPage();
      }, { rootMargin: '300px' });
      observer.current.observe(node);
    },
    [query],
  );
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  const cards = query.data?.pages.flatMap((p) => p.data) ?? [];
  if (query.isLoading) return <Skeletons />;
  if (cards.length === 0) return <EmptyState title={t('pos.noMatchTitle')} body={t('pos.noMatchBody')} />;
  return (
    <>
      <div className="grid grid-cols-2 gap-3 min-[1180px]:grid-cols-3 min-[1700px]:grid-cols-4" data-testid="pos-grid">
        {cards.map((c) => <ProductCard key={c.product_id} card={c} onAdd={(v) => onAdd(c, v)} onOpen={() => onOpen(c)} />)}
      </div>
      <div ref={sentinel} className="h-8" aria-hidden="true" />
      {query.isFetchingNextPage && <Skeletons count={3} />}
    </>
  );
}

function Skeletons({ count = 6 }: { count?: number }) {
  const { t } = useTranslation();
  return (
    <div role="status" aria-label={t('a11y.loading')} className="grid grid-cols-2 gap-3 xl:grid-cols-3 2xl:grid-cols-4">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="card flex flex-col gap-2 p-3"><Skeleton className="aspect-[4/3] h-auto w-full" /><Skeleton className="w-3/4" /><Skeleton className="w-1/2" /></div>
      ))}
    </div>
  );
}
