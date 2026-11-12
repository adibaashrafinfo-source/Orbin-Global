import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, DataTable, EmptyState, PageHeader } from '@/design/components';
import { ApiError } from '@/lib/api';
import { branchesApi, BRANCHES_KEY, locationsApi, LOCATIONS_KEY, warehousesApi, WAREHOUSES_KEY, type Branch, type StorageLocation, type Warehouse } from './api';
import { BranchDialog, LocationDialog, WarehouseDialog } from './dialogs';

function errorOf(e: unknown) {
  return e ? { message: e instanceof Error ? e.message : 'errors.unknown', requestId: e instanceof ApiError ? e.requestId : undefined } : null;
}

function ActiveBadge({ active }: { active: boolean }) {
  const { t } = useTranslation();
  return <Badge tone={active ? 'success' : 'neutral'}>{active ? t('common.active') : t('common.inactive')}</Badge>;
}

/** FR-105 Branches, warehouses and storage locations. */
export function BranchesPage() {
  const { t } = useTranslation();
  const branches = useQuery({ queryKey: BRANCHES_KEY, queryFn: branchesApi.list });
  const warehouses = useQuery({ queryKey: WAREHOUSES_KEY, queryFn: warehousesApi.list });
  const locations = useQuery({ queryKey: LOCATIONS_KEY, queryFn: locationsApi.list });
  const [branchEdit, setBranchEdit] = useState<Branch | 'new' | null>(null);
  const [whEdit, setWhEdit] = useState<Warehouse | 'new' | null>(null);
  const [locEdit, setLocEdit] = useState<StorageLocation | 'new' | null>(null);

  const branchCols = useMemo<ColumnDef<Branch, unknown>[]>(
    () => [
      { accessorKey: 'code', header: t('common.code') },
      { accessorKey: 'name', header: t('common.name') },
      { accessorKey: 'phone', header: t('common.phone'), cell: ({ getValue }) => getValue<string | null>() ?? '—' },
      { id: 'default', header: t('branches.isDefault'), cell: ({ row }) => (row.original.is_default ? <Badge tone="orange">{t('branches.isDefault')}</Badge> : null) },
      { id: 'status', header: t('common.status'), cell: ({ row }) => <ActiveBadge active={row.original.is_active} /> },
    ],
    [t],
  );
  const whCols = useMemo<ColumnDef<Warehouse, unknown>[]>(
    () => [
      { accessorKey: 'code', header: t('common.code') },
      { accessorKey: 'name', header: t('common.name') },
      { accessorKey: 'kind', header: t('branches.kind'), cell: ({ getValue }) => t(`branches.${getValue<string>()}`) },
      { id: 'branch', header: t('common.branch'), cell: ({ row }) => branches.data?.find((b) => b.id === row.original.branch_id)?.name ?? row.original.branch_id },
      { id: 'sellable', header: t('branches.sellable'), cell: ({ row }) => (row.original.is_sellable ? t('common.yes') : t('common.no')) },
      { id: 'status', header: t('common.status'), cell: ({ row }) => <ActiveBadge active={row.original.is_active} /> },
    ],
    [t, branches.data],
  );
  const locCols = useMemo<ColumnDef<StorageLocation, unknown>[]>(
    () => [
      { accessorKey: 'code', header: t('common.code') },
      { accessorKey: 'name', header: t('common.name'), cell: ({ getValue }) => getValue<string | null>() ?? '—' },
      { accessorKey: 'kind', header: t('branches.kind'), cell: ({ getValue }) => t(`branches.${getValue<string>()}`) },
      { id: 'warehouse', header: t('branches.warehouse'), cell: ({ row }) => warehouses.data?.find((w) => w.id === row.original.warehouse_id)?.name ?? row.original.warehouse_id },
      { id: 'parent', header: t('branches.parent'), cell: ({ row }) => locations.data?.find((l) => l.id === row.original.parent_id)?.code ?? '—' },
      { id: 'status', header: t('common.status'), cell: ({ row }) => <ActiveBadge active={row.original.is_active} /> },
    ],
    [t, warehouses.data, locations.data],
  );

  return (
    <>
      <PageHeader title={t('branches.title')} description={t('branches.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('branches.title') }]} />
      <div className="flex flex-col gap-8">
        <section aria-labelledby="branches-h">
          <div className="mb-3 flex items-center justify-between">
            <h2 id="branches-h" className="font-heading text-lg font-bold text-navy-900">{t('branches.branches')}</h2>
            <Button size="sm" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setBranchEdit('new')}>{t('branches.addBranch')}</Button>
          </div>
          <DataTable columns={branchCols} data={branches.data ?? []} loading={branches.isLoading} error={errorOf(branches.error)} onRetry={() => void branches.refetch()} onRowClick={setBranchEdit} caption={t('branches.branches')} />
        </section>
        <section aria-labelledby="wh-h">
          <div className="mb-3 flex items-center justify-between">
            <h2 id="wh-h" className="font-heading text-lg font-bold text-navy-900">{t('branches.warehouses')}</h2>
            <Button size="sm" variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setWhEdit('new')}>{t('branches.addWarehouse')}</Button>
          </div>
          <DataTable columns={whCols} data={warehouses.data ?? []} loading={warehouses.isLoading} error={errorOf(warehouses.error)} onRetry={() => void warehouses.refetch()} onRowClick={setWhEdit} caption={t('branches.warehouses')} />
        </section>
        <section aria-labelledby="loc-h">
          <div className="mb-3 flex items-center justify-between">
            <h2 id="loc-h" className="font-heading text-lg font-bold text-navy-900">{t('branches.locations')}</h2>
            <Button size="sm" variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setLocEdit('new')}>{t('branches.addLocation')}</Button>
          </div>
          <DataTable columns={locCols} data={locations.data ?? []} loading={locations.isLoading} error={errorOf(locations.error)} onRetry={() => void locations.refetch()} onRowClick={setLocEdit} compact caption={t('branches.locations')} empty={<EmptyState body={t('branches.emptyLocations')} action={<Button size="sm" variant="secondary" onClick={() => setLocEdit('new')}>{t('branches.addLocation')}</Button>} />} />
        </section>
      </div>
      <BranchDialog item={branchEdit} onClose={() => setBranchEdit(null)} />
      <WarehouseDialog item={whEdit} branches={branches.data ?? []} onClose={() => setWhEdit(null)} />
      <LocationDialog item={locEdit} warehouses={warehouses.data ?? []} locations={locations.data ?? []} onClose={() => setLocEdit(null)} />
    </>
  );
}
