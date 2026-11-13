import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from './Button';
import { Select } from './Select';

export interface PaginationProps {
  page: number;
  perPage: number;
  total: number;
  onPageChange: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
}

export function Pagination({ page, perPage, total, onPageChange, onPerPageChange }: PaginationProps) {
  const { t } = useTranslation();
  const pages = Math.max(1, Math.ceil(total / perPage));
  const from = total === 0 ? 0 : (page - 1) * perPage + 1;
  const to = Math.min(total, page * perPage);
  return (
    <nav className="flex flex-wrap items-center justify-between gap-3 px-1 pt-3 text-13 text-ink-muted" aria-label="pagination">
      <span className="tabular">{t('common.showing', { from, to, total })}</span>
      <div className="flex items-center gap-3">
        {onPerPageChange && (
          <div className="flex items-center gap-2">
            <span>{t('common.rowsPerPage')}</span>
            <Select value={String(perPage)} onChange={(v) => onPerPageChange(Number(v))} options={['10', '25', '50', '100'].map((v) => ({ value: v, label: v }))} className="w-20" aria-label={t('common.rowsPerPage')} />
          </div>
        )}
        <span className="tabular">{t('common.page', { page, pages })}</span>
        <Button variant="secondary" size="sm" onClick={() => onPageChange(page - 1)} disabled={page <= 1} aria-label={t('common.previous')}>
          <ChevronLeft className="h-4 w-4" aria-hidden="true" />
        </Button>
        <Button variant="secondary" size="sm" onClick={() => onPageChange(page + 1)} disabled={page >= pages} aria-label={t('common.next')}>
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </Button>
      </div>
    </nav>
  );
}
