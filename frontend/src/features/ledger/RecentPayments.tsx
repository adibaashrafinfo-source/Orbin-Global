import { useMutation, useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Ban, CheckCheck, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, DataTable, EmptyState, IconButton, Pagination, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { StopClick } from '@/features/catalog/StopClick';
import { useApiError } from '@/features/catalog/useApiError';
import { fmtDateTime } from '@/features/sales/format';
import { downloadBlob } from '@/features/sales/pos/files';
import { formatMoney } from '@/lib/money';
import { LEDGER_KEYS, paymentApi } from './api';
import { useRefreshMoney } from './hooks';
import { ReasonDialog } from './ReasonDialog';
import type { PartyPayment } from './types';

/** The latest payments, with the receipt, and for those who may verify: confirm a waiting one or void a mistake. */
export function RecentPayments({ partyType, partyId }: { partyType?: string; partyId?: number }) {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [voiding, setVoiding] = useState<PartyPayment | null>(null);
  const params = { page, per_page: perPage, 'filter[party_type]': partyType, 'filter[party_id]': partyId };
  const query = useQuery({ queryKey: [...LEDGER_KEYS.payments, params], queryFn: () => paymentApi.list(params), placeholderData: (p) => p });
  const canVerify = can(['payments.verify']);
  const void_ = useMutation({ mutationFn: (reason: string) => paymentApi.void(voiding!.id, reason), onSuccess: () => { refresh(); toast.success(t('ledger.voided')); setVoiding(null); }, onError });
  const confirm = useMutation({ mutationFn: (p: PartyPayment) => paymentApi.confirm(p.id), onSuccess: () => { refresh(); toast.success(t('ledger.confirmed')); }, onError });
  const save = async (p: PartyPayment) => { try { downloadBlob(await paymentApi.receipt(p.id), `${p.payment_no}.pdf`); } catch { toast.error(t('ledger.receiptFailed')); } };
  const columns = useMemo<ColumnDef<PartyPayment, unknown>[]>(() => [
    { id: 'no', header: t('ledger.paymentNo'), cell: ({ row }) => <span className="font-semibold text-navy-900">{row.original.payment_no}</span> },
    { id: 'date', header: t('ledger.date'), cell: ({ row }) => fmtDateTime(row.original.payment_date, i18n.language, false) },
    { id: 'party', header: t('ledger.party'), cell: ({ row }) => row.original.party?.name ?? '—' },
    { id: 'method', header: t('ledger.method'), cell: ({ row }) => t(`pos.pay.methods.${row.original.method}`) },
    { id: 'amount', header: t('ledger.amount'), cell: ({ row }) => <span className="tabular whitespace-nowrap font-semibold">{row.original.direction === 'pay' ? '− ' : ''}{formatMoney(row.original.amount)}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => row.original.status === 'void' ? <Badge tone="danger">{t('ledger.void')}</Badge> : row.original.verification === 'pending' ? <Badge tone="warning">{t('ledger.pending')}</Badge> : <Badge tone="success">{t('ledger.posted')}</Badge> },
    { id: 'actions', header: () => <span className="sr-only">{t('common.actions')}</span>, cell: ({ row }) => (
      <StopClick className="flex justify-end gap-1">
        <IconButton label={t('ledger.downloadReceipt')} onClick={() => void save(row.original)}><Download className="h-4 w-4" aria-hidden="true" /></IconButton>
        {canVerify && row.original.status === 'posted' && row.original.verification === 'pending' && <IconButton label={t('ledger.confirm')} onClick={() => confirm.mutate(row.original)}><CheckCheck className="h-4 w-4" aria-hidden="true" /></IconButton>}
        {canVerify && row.original.status === 'posted' && <IconButton label={t('ledger.voidPayment')} onClick={() => setVoiding(row.original)}><Ban className="h-4 w-4" aria-hidden="true" /></IconButton>}
      </StopClick>) },
  // eslint-disable-next-line react-hooks/exhaustive-deps -- save and the mutations are stable enough; the table only needs the labels and permission
  ], [t, i18n.language, canVerify]);
  return (
    <section aria-label={t('ledger.recentPayments')} className="mt-6">
      <h3 className="mb-2 font-heading text-sm font-bold text-navy-900">{t('ledger.recentPayments')}</h3>
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} getRowId={(r) => String(r.id)} compact caption={t('ledger.recentPayments')} empty={<EmptyState body={t('ledger.noPayments')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      <ReasonDialog open={voiding !== null} title={t('ledger.voidPaymentTitle', { no: voiding?.payment_no ?? '' })} body={t('ledger.voidPaymentBody')} confirmLabel={t('ledger.voidPayment')} danger loading={void_.isPending} error={void_.error} onCancel={() => setVoiding(null)} onConfirm={(r) => void_.mutate(r)} />
    </section>
  );
}
