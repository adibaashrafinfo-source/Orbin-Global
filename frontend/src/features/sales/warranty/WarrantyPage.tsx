import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, DataTable, EmptyState, Modal, PageHeader, Pagination, Select, Textarea, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { useApiError } from '@/features/catalog/useApiError';
import { useDerivedState } from '@/features/catalog/util';
import { ApiError } from '@/lib/api';
import { SALES_KEYS, warrantyApi } from '../api';
import { fmtDateTime, SALE_TONE } from '../format';
import { SalesTabs } from '../SalesTabs';
import type { WarrantyClaim } from '../types';

const ALL = '__all__';

/** FR-717: warranty claims. A manager moves one to review and resolves it as replaced, refunded or rejected. */
export function WarrantyPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [status, setStatus] = useState('');
  const [edit, setEdit] = useState<WarrantyClaim | null>(null);
  const params = { page, per_page: perPage, 'filter[status]': status };
  const query = useQuery({ queryKey: [...SALES_KEYS.warranty, params], queryFn: () => warrantyApi.list(params), placeholderData: (p) => p });
  const canResolve = can(['sales.return.approve']);
  const columns = useMemo<ColumnDef<WarrantyClaim, unknown>[]>(() => [
    { id: 'no', header: t('warranty.no'), cell: ({ row }) => <span className="font-semibold text-navy-900">{row.original.claim_no}</span> },
    { id: 'invoice', header: t('sales.invoice'), cell: ({ row }) => row.original.invoice_no ?? '—' },
    { id: 'product', header: t('sales.item'), cell: ({ row }) => row.original.product ?? '—' },
    { id: 'customer', header: t('sales.customer'), cell: ({ row }) => row.original.customer ?? '—' },
    { id: 'issue', header: t('warranty.issue'), cell: ({ row }) => <span className="line-clamp-2 max-w-xs">{row.original.issue}</span> },
    { id: 'date', header: t('sales.date'), cell: ({ row }) => fmtDateTime(row.original.claimed_at, i18n.language, false) },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={SALE_TONE[row.original.status] ?? 'neutral'}>{t(`warranty.status.${row.original.status}`)}</Badge> },
  ], [t, i18n.language]);
  const err = query.error ? { message: query.error instanceof ApiError ? t(`errors.${query.error.code}`, { defaultValue: t('errors.unknown') }) : t('errors.unknown'), requestId: undefined } : null;
  return (
    <>
      <PageHeader title={t('nav.sales')} description={t('warranty.subtitle')} crumbs={[{ label: t('nav.sales'), to: '/sales' }, { label: t('sales.tabs.warranty') }]} />
      <SalesTabs />
      <div className="mb-4 w-48"><Select label={t('common.status')} value={status || ALL} onChange={(v) => { setStatus(v === ALL ? '' : v); setPage(1); }} options={[{ value: ALL, label: t('common.all') }, ...['open', 'in_review', 'replaced', 'refunded', 'rejected'].map((x) => ({ value: x, label: t(`warranty.status.${x}`) }))]} /></div>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} error={err} onRetry={() => void query.refetch()} getRowId={(r) => String(r.id)} onRowClick={canResolve ? setEdit : undefined} compact caption={t('sales.tabs.warranty')} empty={<EmptyState body={t('warranty.empty')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      <ResolveDialog claim={edit} onClose={() => setEdit(null)} />
    </>
  );
}

function ResolveDialog({ claim, onClose }: { claim: WarrantyClaim | null; onClose: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const [next, setNext] = useDerivedState(claim?.id, () => 'in_review');
  const [resolution, setResolution] = useDerivedState(claim?.id, () => '');
  const final = claim && ['replaced', 'refunded', 'rejected'].includes(claim.status);
  const save = useMutation({
    mutationFn: () => warrantyApi.update(claim?.id ?? 0, { status: next, resolution: resolution.trim() || undefined }),
    onSuccess: () => { void client.invalidateQueries({ queryKey: SALES_KEYS.warranty }); void client.invalidateQueries({ queryKey: ['inventory'] }); toast.success(t('common.saved')); onClose(); },
    onError,
  });
  return (
    <Modal open={claim !== null} onOpenChange={(o) => !o && onClose()} className="max-w-md" title={claim?.claim_no ?? ''} description={claim ? `${claim.product ?? ''} · ${claim.invoice_no ?? ''}` : undefined}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.close')}</Button>{!final && <Button loading={save.isPending} onClick={() => save.mutate()}>{t('common.save')}</Button>}</>}>
      {claim && (
        <div className="flex flex-col gap-3">
          <p className="rounded-card bg-navy-50 p-3 text-sm">{claim.issue}</p>
          {final ? <p className="text-sm"><span className="font-semibold">{t(`warranty.status.${claim.status}`)}</span>{claim.resolution ? ` · ${claim.resolution}` : ''}</p> : (
            <>
              <Select label={t('warranty.outcome')} value={next} onChange={setNext} options={['in_review', 'replaced', 'refunded', 'rejected'].map((x) => ({ value: x, label: t(`warranty.status.${x}`) }))} />
              {next === 'replaced' && <p className="text-xs text-ink-muted">{t('warranty.replaceNote')}</p>}
              <Textarea label={t('warranty.resolution')} rows={3} value={resolution} onChange={(e) => setResolution(e.target.value)} />
            </>
          )}
        </div>
      )}
    </Modal>
  );
}
