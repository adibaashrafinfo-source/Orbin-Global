import type { ColumnDef } from '@tanstack/react-table';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/design/components';
import { formatMoney } from '@/lib/money';
import { DocListPage } from '../stock/DocListPage';
import { StatusBadge } from '../stock/StatusBadge';
import type { Doc } from '../stock/types';
import { str } from '../catalog/util';
import { PurchasingTabs } from './PurchasingTabs';

/** FR-503 */
export function ReceiptsPage() {
  const { t } = useTranslation();
  const columns = useMemo<ColumnDef<Doc, unknown>[]>(() => [
    { id: 'no', header: t('stock.number'), cell: ({ row }) => <span className="font-semibold">{String(row.original.grn_no)}</span> },
    { id: 'supplier', header: t('purchasing.supplier'), cell: ({ row }) => row.original.supplier?.name ?? '—' },
    { id: 'invoice', header: t('purchasing.supplierInvoice'), cell: ({ row }) => str(row.original.supplier_invoice_no, '—') },
    { id: 'date', header: t('purchasing.receivedDate'), cell: ({ row }) => String(row.original.received_date) },
    { id: 'wh', header: t('inventory.warehouse'), cell: ({ row }) => row.original.warehouse?.name ?? '—' },
    { id: 'total', header: t('purchasing.landedTotal'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{formatMoney(String(row.original.grand_total))}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ], [t]);
  return (
    <DocListPage resource="goods-receipts" base="/purchases/receipts" statuses={['draft', 'posted', 'cancelled']} statusLabel={(s) => t(`stock.status.${s}`)} columns={columns}
      createLabel={t('purchasing.newReceipt')} createPermission={['purchases.grn', 'purchases.manage']} emptyText={t('purchasing.emptyReceipts')} caption={t('purchasing.tabs.receipts')}
      header={<><PageHeader title={t('nav.purchases')} description={t('purchasing.receiptsSubtitle')} crumbs={[{ label: t('nav.purchases') }, { label: t('purchasing.tabs.receipts') }]} /><PurchasingTabs /></>} />
  );
}
