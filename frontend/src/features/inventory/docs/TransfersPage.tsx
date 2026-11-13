import type { ColumnDef } from '@tanstack/react-table';
import { ArrowRight } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/design/components';
import { formatDateTime } from '@/lib/format';
import { DocListPage } from '../../stock/DocListPage';
import { StatusBadge } from '../../stock/StatusBadge';
import type { Doc } from '../../stock/types';
import { str } from '../../catalog/util';
import { InventoryTabs } from '../InventoryTabs';

/** FR-405 */
export function TransfersPage() {
  const { t, i18n } = useTranslation();
  const columns = useMemo<ColumnDef<Doc, unknown>[]>(() => [
    { id: 'no', header: t('stock.number'), cell: ({ row }) => <span className="font-semibold">{String(row.original.transfer_no)}</span> },
    { id: 'route', header: t('inventory.route'), cell: ({ row }) => <span className="flex items-center gap-1">{row.original.from_warehouse?.name}<ArrowRight className="h-3.5 w-3.5 text-ink-subtle" aria-hidden="true" />{row.original.to_warehouse?.name}</span> },
    { id: 'sent', header: t('inventory.sentAt'), cell: ({ row }) => (row.original.sent_at ? formatDateTime(str(row.original.sent_at), i18n.language) : '—') },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ], [t, i18n.language]);
  return (
    <DocListPage resource="stock-transfers" base="/inventory/transfers" statuses={['draft', 'in_transit', 'received', 'cancelled']} statusLabel={(s) => t(`stock.status.${s}`)} columns={columns}
      createLabel={t('inventory.newTransfer')} createPermission={['inventory.transfer']} emptyText={t('inventory.emptyTransfers')} caption={t('inventory.tabs.transfers')}
      header={<><PageHeader title={t('nav.inventory')} description={t('inventory.transfersSubtitle')} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.transfers') }]} /><InventoryTabs /></>} />
  );
}
