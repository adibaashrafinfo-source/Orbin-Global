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

const STATUSES = ['draft', 'approved', 'partially_received', 'received', 'closed', 'cancelled'];

/** FR-502 */
export function OrdersPage() {
  const { t } = useTranslation();
  const columns = useMemo<ColumnDef<Doc, unknown>[]>(() => [
    { id: 'no', header: t('stock.number'), cell: ({ row }) => <span className="font-semibold">{String(row.original.po_no)}</span> },
    { id: 'supplier', header: t('purchasing.supplier'), cell: ({ row }) => row.original.supplier?.name ?? '—' },
    { id: 'date', header: t('purchasing.orderDate'), cell: ({ row }) => String(row.original.order_date) },
    { id: 'expected', header: t('purchasing.expectedDate'), cell: ({ row }) => str(row.original.expected_date, '—') },
    { id: 'total', header: t('purchasing.grandTotal'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{formatMoney(String(row.original.grand_total))}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ], [t]);
  return (
    <DocListPage resource="purchase-orders" base="/purchases/orders" statuses={STATUSES} statusLabel={(s) => t(`stock.status.${s}`)} columns={columns}
      createLabel={t('purchasing.newOrder')} createPermission={['purchases.manage']} emptyText={t('purchasing.emptyOrders')} caption={t('purchasing.tabs.orders')}
      header={<><PageHeader title={t('nav.purchases')} description={t('purchasing.ordersSubtitle')} crumbs={[{ label: t('nav.purchases') }, { label: t('purchasing.tabs.orders') }]} /><PurchasingTabs /></>} />
  );
}
