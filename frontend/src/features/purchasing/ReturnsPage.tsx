import type { ColumnDef } from '@tanstack/react-table';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/design/components';
import { formatMoney } from '@/lib/money';
import { DocListPage } from '../stock/DocListPage';
import { StatusBadge } from '../stock/StatusBadge';
import type { Doc } from '../stock/types';
import { PurchasingTabs } from './PurchasingTabs';

/** FR-504 */
export function ReturnsPage() {
  const { t } = useTranslation();
  const columns = useMemo<ColumnDef<Doc, unknown>[]>(() => [
    { id: 'no', header: t('stock.number'), cell: ({ row }) => <span className="font-semibold">{String(row.original.return_no)}</span> },
    { id: 'supplier', header: t('purchasing.supplier'), cell: ({ row }) => row.original.supplier?.name ?? '—' },
    { id: 'date', header: t('purchasing.returnDate'), cell: ({ row }) => String(row.original.return_date) },
    { id: 'total', header: t('purchasing.returnTotal'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{formatMoney(String(row.original.total))}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ], [t]);
  return (
    <DocListPage resource="purchase-returns" base="/purchases/returns" statuses={['draft', 'posted', 'cancelled']} statusLabel={(s) => t(`stock.status.${s}`)} columns={columns}
      createLabel={t('purchasing.newReturn')} createPermission={['purchases.manage']} emptyText={t('purchasing.emptyReturns')} caption={t('purchasing.tabs.returns')}
      header={<><PageHeader title={t('nav.purchases')} description={t('purchasing.returnsSubtitle')} crumbs={[{ label: t('nav.purchases') }, { label: t('purchasing.tabs.returns') }]} /><PurchasingTabs /></>} />
  );
}
