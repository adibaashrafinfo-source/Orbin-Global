import { flexRender, getCoreRowModel, getSortedRowModel, useReactTable, type ColumnDef, type SortingState } from '@tanstack/react-table';
import { motion } from 'framer-motion';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { listItemVariants, listVariants, STAGGER_MAX_ITEMS } from '../motion';
import { useMotionSafe } from '../hooks/useMotionSafe';
import { EmptyState, ErrorState } from './States';
import { TableSkeleton } from './Skeleton';

export interface DataTableProps<T> {
  columns: ColumnDef<T, unknown>[];
  data: T[];
  loading?: boolean;
  error?: { message: string; requestId?: string | undefined } | null | undefined;
  onRetry?: () => void;
  empty?: ReactNode;
  compact?: boolean;
  getRowId?: (row: T) => string;
  onRowClick?: (row: T) => void;
  /** Server-side sorting: emit changes instead of sorting locally. */
  sorting?: SortingState;
  onSortingChange?: (s: SortingState) => void;
  caption?: string;
}

/**
 * Table per designSystem.components.table: sticky navy header, 52/40px rows, hover navy-100,
 * sticky first column, list stagger (max 12 rows animated). Pagination is server-side (see Pagination).
 */
export function DataTable<T>({ columns, data, loading, error, onRetry, empty, compact, getRowId, onRowClick, sorting, onSortingChange, caption }: DataTableProps<T>) {
  const { t } = useTranslation();
  const [localSorting, setLocalSorting] = useState<SortingState>([]);
  const controlled = sorting !== undefined;
  const table = useReactTable({
    data,
    columns,
    getRowId,
    state: { sorting: controlled ? sorting : localSorting },
    onSortingChange: (updater) => {
      const next = typeof updater === 'function' ? updater(controlled ? sorting : localSorting) : updater;
      if (controlled) onSortingChange?.(next);
      else setLocalSorting(next);
    },
    manualSorting: controlled,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: controlled ? undefined : getSortedRowModel(),
  });
  const { variants: listV, reduced } = useMotionSafe(listVariants);

  return (
    <div className="card overflow-hidden">
      <div className="relative overflow-x-auto">
        <table className="w-full border-collapse text-sm">
          {caption && <caption className="sr-only">{caption}</caption>}
          <thead className="table-head sticky top-0 z-10">
            {table.getHeaderGroups().map((hg) => (
              <tr key={hg.id}>
                {hg.headers.map((header, idx) => {
                  const canSort = header.column.getCanSort();
                  const sorted = header.column.getIsSorted();
                  const label = flexRender(header.column.columnDef.header, header.getContext());
                  return (
                    <th
                      key={header.id}
                      scope="col"
                      className={cn('h-11 px-4 text-left font-semibold', idx === 0 && 'sticky left-0 z-20 bg-navy-900', header.column.columnDef.meta?.align === 'right' && 'text-right')}
                      aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : undefined}
                    >
                      {canSort ? (
                        <button type="button" onClick={header.column.getToggleSortingHandler()} className="inline-flex items-center gap-1 rounded focus-visible:focus-ring-dark" aria-label={t('a11y.sortBy', { column: typeof label === 'string' ? label : '' })}>
                          {label}
                          {sorted === 'asc' ? <ArrowUp className="h-3.5 w-3.5" aria-hidden="true" /> : sorted === 'desc' ? <ArrowDown className="h-3.5 w-3.5" aria-hidden="true" /> : <ArrowUpDown className="h-3.5 w-3.5 opacity-60" aria-hidden="true" />}
                        </button>
                      ) : (
                        label
                      )}
                    </th>
                  );
                })}
              </tr>
            ))}
          </thead>
          {!loading && !error && data.length > 0 && (
            <motion.tbody variants={listV} initial="hidden" animate="show">
              {table.getRowModel().rows.map((row, i) => (
                <motion.tr
                  key={row.id}
                  variants={i < STAGGER_MAX_ITEMS && !reduced ? listItemVariants : undefined}
                  onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                  className={cn('border-b border-navy-100 bg-surface transition-colors duration-fast hover:bg-navy-100', compact ? 'h-10' : 'h-[52px]', onRowClick && 'cursor-pointer')}
                >
                  {row.getVisibleCells().map((cell, idx) => (
                    <td key={cell.id} className={cn('px-4 align-middle', idx === 0 && 'sticky left-0 bg-inherit', cell.column.columnDef.meta?.align === 'right' && 'text-right tabular')}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </td>
                  ))}
                </motion.tr>
              ))}
            </motion.tbody>
          )}
        </table>
      </div>
      {loading && <TableSkeleton cols={Math.min(columns.length, 5)} />}
      {!loading && error && <ErrorState body={error.message} requestId={error.requestId} onRetry={onRetry} />}
      {!loading && !error && data.length === 0 && (empty ?? <EmptyState />)}
    </div>
  );
}
