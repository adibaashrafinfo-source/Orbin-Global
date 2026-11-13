import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Download, FileSpreadsheet, FileText, Printer } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, toast } from '@/design/components';
import { downloadBlob } from '@/features/sales/pos/files';
import { reportApi } from './api';
import type { Filters } from './types';

const ITEM = 'flex cursor-pointer items-center gap-2 rounded-input px-3 py-2 text-sm text-ink outline-none data-[highlighted]:bg-navy-50';

/** CSV, Excel and PDF for the report as filtered on screen, and a print view (the PDF opened in a tab, where the browser prints it). */
export function ExportMenu({ id, filters }: { id: string; filters: Filters }) {
  const { t } = useTranslation();
  const [busy, setBusy] = useState(false);
  const save = async (format: 'csv' | 'xlsx' | 'pdf') => {
    setBusy(true);
    try {
      downloadBlob(await reportApi.file(id, filters, format), `Orbin_${id}_${filters.from}_${filters.to}.${format}`);
    } catch {
      toast.error(t('reports.exportFailed'));
    } finally {
      setBusy(false);
    }
  };
  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger asChild>
        <Button variant="secondary" loading={busy} leftIcon={<Download className="h-4 w-4" aria-hidden="true" />} data-testid="export-menu">{t('reports.export')}</Button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Portal>
        <DropdownMenu.Content align="end" sideOffset={6} className="z-50 min-w-44 rounded-card border border-navy-100 bg-surface p-1 shadow-lg">
          <DropdownMenu.Item className={ITEM} onSelect={() => void save('csv')} data-testid="export-csv"><FileText className="h-4 w-4 text-navy-700" aria-hidden="true" />{t('reports.formats.csv')}</DropdownMenu.Item>
          <DropdownMenu.Item className={ITEM} onSelect={() => void save('xlsx')} data-testid="export-xlsx"><FileSpreadsheet className="h-4 w-4 text-navy-700" aria-hidden="true" />{t('reports.formats.xlsx')}</DropdownMenu.Item>
          <DropdownMenu.Item className={ITEM} onSelect={() => void save('pdf')} data-testid="export-pdf"><FileText className="h-4 w-4 text-navy-700" aria-hidden="true" />{t('reports.formats.pdf')}</DropdownMenu.Item>
          <DropdownMenu.Separator className="my-1 h-px bg-navy-100" />
          <DropdownMenu.Item className={ITEM} onSelect={() => window.open(reportApi.exportUrl(id, filters, 'pdf', true), '_blank', 'noopener')} data-testid="export-print"><Printer className="h-4 w-4 text-navy-700" aria-hidden="true" />{t('reports.print')}</DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  );
}
