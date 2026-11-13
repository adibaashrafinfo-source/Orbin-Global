import { ArrowDown, ArrowUp } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/cn';
import type { ReportColumn, ReportRow } from './types';
import { cellText, docRoute, labelKey } from './util';

const NUMERIC = ['int', 'qty', 'money', 'cost', 'percent'];

/** A report's rows with sortable headers, document links and the totals row. Sorting happens on the server so it covers every page. */
export function ReportTable({ columns, rows, totals, sort, dir, onSort }: { columns: ReportColumn[]; rows: ReportRow[]; totals: Record<string, string>; sort: string; dir: 'asc' | 'desc'; onSort: (key: string) => void }) {
  const { t } = useTranslation();
  const hasTotals = Object.keys(totals).length > 0;
  return (
    <div className="card overflow-x-auto">
      <table className="w-full text-sm" data-testid="report-table">
        <thead>
          <tr className="bg-navy-900 text-surface">
            {columns.map((c) => {
              const active = sort === c.key;
              return (
                <th key={c.key} scope="col" aria-sort={active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'} className={cn('whitespace-nowrap px-3 py-2.5 text-xs font-semibold', NUMERIC.includes(c.type) ? 'text-end' : 'text-start')}>
                  <button type="button" onClick={() => onSort(c.key)} className="inline-flex items-center gap-1 focus-visible:focus-ring-dark">
                    {t(`reports.col.${labelKey(c.label)}`, { defaultValue: c.label })}
                    {active && (dir === 'asc' ? <ArrowUp className="h-3 w-3" aria-hidden="true" /> : <ArrowDown className="h-3 w-3" aria-hidden="true" />)}
                  </button>
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} className="border-t border-navy-100 hover:bg-navy-50">
              {columns.map((c) => {
                const text = cellText(c, r[c.key]);
                const link = r._links?.[c.key];
                const to = link ? docRoute(link.table, link.id) : null;
                return <td key={c.key} className={cn('px-3 py-2', NUMERIC.includes(c.type) ? 'tabular whitespace-nowrap text-end' : 'text-start')}>{to ? <Link to={to} className="font-semibold text-navy-800 hover:underline focus-visible:focus-ring-light">{text}</Link> : text}</td>;
              })}
            </tr>
          ))}
        </tbody>
        {hasTotals && (
          <tfoot>
            <tr className="border-t-2 border-orange-500 bg-navy-50 font-bold text-navy-900">
              {columns.map((c, i) => <td key={c.key} className={cn('px-3 py-2.5', NUMERIC.includes(c.type) ? 'tabular text-end' : 'text-start')}>{i === 0 ? t('reports.total') : cellText(c, totals[c.key])}</td>)}
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  );
}
