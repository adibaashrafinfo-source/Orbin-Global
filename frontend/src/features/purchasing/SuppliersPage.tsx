import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { FileText, HandCoins, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Badge, Button, ConfirmDialog, DataTable, EmptyState, IconButton, Input, PageHeader, Pagination, Select, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { useApiError } from '../catalog/useApiError';
import { STOCK_KEYS, supplierApi } from '../stock/api';
import { StopClick } from '../catalog/StopClick';
import type { Supplier } from '../stock/types';
import { PurchasingTabs } from './PurchasingTabs';
import { SupplierDialog } from './SupplierDialog';

const ALL = '__all__';

/** FR-501 */
export function SuppliersPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const client = useQueryClient();
  const onError = useApiError();
  const canManage = can(['suppliers.manage']);
  const navigate = useNavigate();
  const canPay = can(['ledger.pay_supplier']);
  const canLedger = can(['ledger.view']);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const [edit, setEdit] = useState<Supplier | 'new' | null>(null);
  const [del, setDel] = useState<Supplier | null>(null);
  const q = useDebouncedValue(search, 250);
  const params = { page, per_page: perPage, search: q, 'filter[supplier_type]': type };
  const query = useQuery({ queryKey: [...STOCK_KEYS.suppliers, params], queryFn: () => supplierApi.list(params), placeholderData: (prev) => prev });
  const remove = useMutation({ mutationFn: (s: Supplier) => supplierApi.remove(s.id), onSuccess: () => { void client.invalidateQueries({ queryKey: STOCK_KEYS.suppliers }); toast.success(t('masterData.deleted')); setDel(null); }, onError: (e) => { setDel(null); onError(e); } });

  const columns = useMemo<ColumnDef<Supplier, unknown>[]>(() => {
    const cols: ColumnDef<Supplier, unknown>[] = [
      { id: 'code', header: t('common.code'), cell: ({ row }) => <code className="text-xs font-semibold">{row.original.code}</code> },
      { id: 'name', header: t('purchasing.fields.name'), cell: ({ row }) => <div><div className="font-medium">{row.original.name}</div><div className="text-xs text-ink-muted">{row.original.contact_person}</div></div> },
      { id: 'phones', header: t('purchasing.fields.phones'), cell: ({ row }) => row.original.phones.join(', ') || '—' },
      { id: 'type', header: t('purchasing.fields.type'), cell: ({ row }) => <Badge tone={row.original.supplier_type === 'import' ? 'orange' : 'navy'}>{t(`purchasing.types.${row.original.supplier_type}`)}</Badge> },
      { id: 'terms', header: t('purchasing.fields.terms'), cell: ({ row }) => <span className="tabular">{row.original.payment_terms_days}</span> },
      { id: 'balance', header: t('purchasing.fields.balance'), cell: ({ row }) => <span className={`tabular whitespace-nowrap font-semibold ${Number(row.original.balance) > 0 ? 'text-ink' : 'text-ink-muted'}`}>{formatMoney(row.original.balance)}</span> },
      { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={row.original.is_active ? 'success' : 'neutral'}>{row.original.is_active ? t('common.active') : t('common.inactive')}</Badge> },
    ];
    if (canManage || canPay || canLedger) cols.push({ id: 'actions', header: () => <span className="sr-only">{t('common.actions')}</span>, cell: ({ row }) => (
      <StopClick className="flex justify-end gap-1">
        {canPay && <IconButton label={t('ledger.makePayment')} onClick={() => navigate(`/ledger/receive?type=supplier&id=${row.original.id}&code=${encodeURIComponent(row.original.code)}&name=${encodeURIComponent(row.original.name)}`)}><HandCoins className="h-4 w-4" aria-hidden="true" /></IconButton>}
        {canLedger && <IconButton label={t('ledger.tabs.parties')} onClick={() => navigate(`/ledger/parties?type=supplier&id=${row.original.id}&code=${encodeURIComponent(row.original.code)}&name=${encodeURIComponent(row.original.name)}`)}><FileText className="h-4 w-4" aria-hidden="true" /></IconButton>}
        {canManage && <IconButton label={t('common.delete')} onClick={() => setDel(row.original)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>}
      </StopClick>) });
    return cols;
  }, [t, canManage, canPay, canLedger, navigate]);

  const add = canManage ? <Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setEdit('new')}>{t('purchasing.addSupplier')}</Button> : null;
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.purchases')} description={t('purchasing.suppliersSubtitle')} crumbs={[{ label: t('nav.purchases') }, { label: t('purchasing.tabs.suppliers') }]} />
      <PurchasingTabs />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-64"><Input label={t('common.search')} type="search" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} /></div>
        <div className="w-44"><Select label={t('purchasing.fields.type')} value={type || ALL} onChange={(v) => { setType(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, { value: 'local', label: t('purchasing.types.local') }, { value: 'import', label: t('purchasing.types.import') }]} /></div>
        <div className="ms-auto">{add}</div>
      </div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(s) => String(s.id)} onRowClick={setEdit} compact caption={t('purchasing.tabs.suppliers')} empty={<EmptyState body={t('purchasing.emptySuppliers')} action={add} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      <SupplierDialog supplier={edit} canManage={canManage} onClose={() => setEdit(null)} />
      <ConfirmDialog open={del !== null} onOpenChange={(o) => !o && setDel(null)} danger title={t('stock.deleteTitle')} body={t('purchasing.deleteSupplierBody', { name: del?.name ?? '' })} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (del) remove.mutate(del); }} />
    </>
  );
}
