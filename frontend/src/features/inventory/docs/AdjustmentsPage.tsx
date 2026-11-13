import type { ColumnDef } from '@tanstack/react-table';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/design/components';
import { formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { DocListPage } from '../../stock/DocListPage';
import { StatusBadge } from '../../stock/StatusBadge';
import type { Doc } from '../../stock/types';
import { str } from '../../catalog/util';
import { InventoryTabs } from '../InventoryTabs';

/** FR-403: stock adjustments, with the ones waiting for approval easy to find. */
export function AdjustmentsPage() {
  const { t, i18n } = useTranslation();
  const columns = useMemo<ColumnDef<Doc, unknown>[]>(() => [
    { id: 'no', header: t('stock.number'), cell: ({ row }) => <span className="font-semibold">{String(row.original.adj_no)}</span> },
    { id: 'wh', header: t('inventory.warehouse'), cell: ({ row }) => row.original.warehouse?.name ?? '—' },
    { id: 'reason', header: t('inventory.reason'), cell: ({ row }) => (i18n.language === 'bn' && row.original.reason?.label_bn) || row.original.reason?.label_en || '—' },
    { id: 'value', header: t('inventory.value'), cell: ({ row }) => (row.original.total_value !== undefined ? <span className="tabular whitespace-nowrap">{formatMoney(str(row.original.total_value))}</span> : '—') },
    { id: 'when', header: t('inventory.when'), cell: ({ row }) => formatDateTime(String(row.original.created_at), i18n.language) },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ], [t, i18n.language]);
  return (
    <DocListPage resource="stock-adjustments" base="/inventory/adjustments" statuses={['draft', 'pending_approval', 'posted', 'rejected']} statusLabel={(s) => t(`stock.status.${s}`)} columns={columns}
      createLabel={t('inventory.newAdjustment')} createPermission={['inventory.adjust']} emptyText={t('inventory.emptyAdjustments')} caption={t('inventory.tabs.adjustments')}
      header={<><PageHeader title={t('nav.inventory')} description={t('inventory.adjustmentsSubtitle')} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.adjustments') }]} /><InventoryTabs /></>} />
  );
}
