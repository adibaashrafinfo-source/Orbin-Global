import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Download, LayoutGrid, Percent, Plus, SlidersHorizontal, Table2, Tag, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, Button, DataTable, EmptyState, ErrorState, IconButton, Input, PageHeader, Pagination, Skeleton } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { ApiError } from '@/lib/api';
import { importApi, KEYS, productApi } from '../api';
import type { Product } from '../types';
import { BulkPriceDialog } from './BulkPriceDialog';
import { ProductCard } from './ProductCard';
import { priceRange } from './priceRange';
import { ProductFiltersDrawer } from './ProductFiltersDrawer';
import { activeFilterCount, EMPTY_FILTERS, useViewMode, type Filters } from './useProductFilters';

/** FR-301 Product list: grid or table, search, filters drawer, export, bulk price. */
export function ProductsPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const navigate = useNavigate();
  const [view, setView] = useViewMode();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
  const [drawer, setDrawer] = useState(false);
  const [bulk, setBulk] = useState(false);
  const q = useDebouncedValue(search, 250);
  const query = useQuery({
    queryKey: [...KEYS.products, 'list', { page, perPage, q, filters }],
    queryFn: () => productApi.list({ page, per_page: perPage, search: q, sort: 'name', filter: filters }),
    placeholderData: (prev) => prev,
  });
  const canManage = can(['products.manage']);
  const active = activeFilterCount(filters);

  const columns = useMemo<ColumnDef<Product, unknown>[]>(() => [
    { id: 'name', header: t('products.fields.name'), cell: ({ row }) => <Link to={`/products/${row.original.id}`} className="font-medium text-navy-800 hover:underline">{row.original.name_en}</Link> },
    { accessorKey: 'code', header: t('products.fields.code') },
    { accessorKey: 'category', header: t('products.fields.category'), cell: ({ getValue }) => getValue<string | null>() ?? '—' },
    { accessorKey: 'brand', header: t('products.fields.brand'), cell: ({ getValue }) => getValue<string | null>() ?? '—' },
    { id: 'type', header: t('products.fields.mirrorType'), cell: ({ row }) => t(`products.mirrorTypes.${row.original.mirror_type}`) },
    { id: 'variants', header: t('products.variants'), cell: ({ row }) => row.original.variants_count ?? 0 },
    { id: 'stock', header: t('products.fields.onHand'), cell: ({ row }) => (row.original.stock_total === null ? '—' : <span className={`tabular ${Number(row.original.stock_total) > 0 ? '' : 'text-danger'}`}>{String(Number(row.original.stock_total))}</span>) },
    { id: 'price', header: t('products.fields.retail_price'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{priceRange(row.original)}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={row.original.is_active ? 'success' : 'warning'}>{row.original.is_active ? t('products.published') : t('products.draft')}</Badge> },
  ], [t]);

  const addButton = canManage ? <Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => navigate('/products/new')}>{t('products.addProduct')}</Button> : null;
  const total = query.data?.meta.total ?? 0;
  const items = query.data?.data ?? [];
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: query.error instanceof ApiError ? query.error.requestId : undefined } : null;

  return (
    <>
      <PageHeader title={t('products.title')} description={t('products.subtitle')} crumbs={[{ label: t('products.title') }]} actions={addButton} />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-72"><Input label={t('common.search')} type="search" placeholder={t('products.searchPlaceholder')} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} /></div>
        <Button variant="secondary" leftIcon={<SlidersHorizontal className="h-4 w-4" aria-hidden="true" />} onClick={() => setDrawer(true)}>
          {t('products.filters')}{active > 0 && <Badge tone="orange" className="ms-1">{active}</Badge>}
        </Button>
        <div className="ms-auto flex items-center gap-1">
          {can(['pricing.manage']) && <Button variant="secondary" size="sm" leftIcon={<Percent className="h-4 w-4" aria-hidden="true" />} onClick={() => setBulk(true)}>{t('products.bulk.open')}</Button>}
          <Button variant="secondary" size="sm" leftIcon={<Tag className="h-4 w-4" aria-hidden="true" />} onClick={() => navigate('/products/labels')}>{t('products.labels')}</Button>
          {can(['products.import']) && <Button variant="secondary" size="sm" leftIcon={<Upload className="h-4 w-4" aria-hidden="true" />} onClick={() => navigate('/products/import')}>{t('products.import')}</Button>}
          {can(['products.import', 'reports.export']) && (
            <a href={importApi.exportUrl('csv', q)} download className="inline-flex h-9 items-center gap-2 rounded-input border border-navy-200 bg-surface px-3 text-sm font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light">
              <Download className="h-4 w-4" aria-hidden="true" />{t('products.export')}
            </a>
          )}
          <span className="mx-1 h-6 w-px bg-navy-200" aria-hidden="true" />
          <IconButton label={t('products.viewGrid')} aria-pressed={view === 'grid'} variant={view === 'grid' ? 'secondary' : 'ghost'} onClick={() => setView('grid')}><LayoutGrid className="h-4 w-4" aria-hidden="true" /></IconButton>
          <IconButton label={t('products.viewTable')} aria-pressed={view === 'table'} variant={view === 'table' ? 'secondary' : 'ghost'} onClick={() => setView('table')}><Table2 className="h-4 w-4" aria-hidden="true" /></IconButton>
        </div>
      </div>

      {view === 'table' ? (
        <DataTable columns={columns} data={items} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(p) => String(p.id)} onRowClick={(p) => navigate(`/products/${p.id}`)} caption={t('products.title')} empty={<EmptyState body={t('products.empty')} action={addButton} />} />
      ) : query.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{Array.from({ length: 8 }, (_, i) => <Skeleton key={i} className="h-56" />)}</div>
      ) : err ? (
        <ErrorState body={err.message} requestId={err.requestId} onRetry={() => void query.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState body={t('products.empty')} action={addButton} />
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" aria-label={t('products.title')}>
          {items.map((p) => <li key={p.id}><ProductCard product={p} /></li>)}
        </ul>
      )}
      {query.data && <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}

      <ProductFiltersDrawer open={drawer} onOpenChange={setDrawer} value={filters} onChange={(f) => { setFilters(f); setPage(1); }} />
      <BulkPriceDialog open={bulk} onClose={() => setBulk(false)} filters={filters} />
    </>
  );
}
