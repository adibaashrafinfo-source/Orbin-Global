import type { ColumnDef } from '@tanstack/react-table';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, PageHeader } from '@/design/components';
import { formatDateTime } from '@/lib/format';
import { DocListPage } from '../../stock/DocListPage';
import { StatusBadge } from '../../stock/StatusBadge';
import type { Doc } from '../../stock/types';
import { str } from '../../catalog/util';
import { InventoryTabs } from '../InventoryTabs';

/** FR-406 */
export function CountsPage() {
  const { t, i18n } = useTranslation();
  const columns = useMemo<ColumnDef<Doc, unknown>[]>(() => [
    { id: 'no', header: t('stock.number'), cell: ({ row }) => <span className="font-semibold">{String(row.original.count_no)}</span> },
    { id: 'wh', header: t('inventory.warehouse'), cell: ({ row }) => row.original.warehouse?.name ?? '—' },
    { id: 'scope', header: t('inventory.scope'), cell: ({ row }) => <span className="flex gap-1">{t(`inventory.scopes.${String(row.original.scope)}`)}{row.original.is_blind ? <Badge tone="navy">{t('inventory.blind')}</Badge> : null}</span> },
    { id: 'started', header: t('inventory.startedAt'), cell: ({ row }) => (row.original.started_at ? formatDateTime(str(row.original.started_at), i18n.language) : '—') },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ], [t, i18n.language]);
  return (
    <DocListPage resource="stock-counts" base="/inventory/counts" statuses={['draft', 'counting', 'review', 'posted', 'cancelled']} statusLabel={(s) => t(`stock.status.${s}`)} columns={columns}
      createLabel={t('inventory.newCount')} createPermission={['inventory.count']} emptyText={t('inventory.emptyCounts')} caption={t('inventory.tabs.counts')}
      header={<><PageHeader title={t('nav.inventory')} description={t('inventory.countsSubtitle')} crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.counts') }]} /><InventoryTabs /></>} />
  );
}
