import { useMutation, useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { ArrowLeftRight, Ban } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, DataTable, EmptyState, IconButton, Input, Modal, PageHeader, Pagination, Select, Textarea, toast } from '@/design/components';
import { StopClick } from '@/features/catalog/StopClick';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { fmtDateTime } from '@/features/sales/format';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { LEDGER_KEYS, transferApi } from './api';
import { today, useAccounts, useRefreshMoney } from './hooks';
import { LedgerTabs } from './LedgerTabs';
import { ReasonDialog } from './ReasonDialog';
import type { FundTransfer } from './types';

function TransferDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const accounts = useAccounts();
  const [f, setF] = useState({ from_account_id: '', to_account_id: '', amount: '', fee_amount: '', transfer_date: today(), note: '' });
  const set = (k: keyof typeof f, v: string) => setF((s) => ({ ...s, [k]: v }));
  const options = (accounts.data ?? []).filter((a) => a.is_active).map((a) => ({ value: String(a.id), label: a.balance !== undefined ? `${a.name} · ${t('ledger.accounts.balanceShort', { amount: formatMoney(a.balance) })}` : a.name }));
  const save = useMutation({
    mutationFn: () => transferApi.create({ ...f, from_account_id: Number(f.from_account_id), to_account_id: Number(f.to_account_id) }),
    onSuccess: (x) => { refresh(); toast.success(t('ledger.transfers.saved', { no: x.transfer_no })); setF({ from_account_id: '', to_account_id: '', amount: '', fee_amount: '', transfer_date: today(), note: '' }); onClose(); },
    onError: (e) => { if (e instanceof ApiError && e.code === 'INSUFFICIENT_FUNDS') return; onError(e); },
  });
  const insufficient = save.error instanceof ApiError && save.error.code === 'INSUFFICIENT_FUNDS' ? t('errors.INSUFFICIENT_FUNDS') : undefined;
  const err = (k: string) => serverFieldError(t, save.error, k);
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-xl" title={t('ledger.transfers.add')} description={t('ledger.transfers.body')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={save.isPending} disabled={f.from_account_id === '' || f.to_account_id === '' || f.from_account_id === f.to_account_id || f.amount === '' || Number(f.amount) <= 0} onClick={() => save.mutate()} data-testid="transfer-save">{t('ledger.transfers.confirm')}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('ledger.transfers.from')} required value={f.from_account_id} onChange={(v) => set('from_account_id', v)} placeholder={t('common.select')} options={options} error={insufficient ?? err('from_account_id')} />
        <Select label={t('ledger.transfers.to')} required value={f.to_account_id} onChange={(v) => set('to_account_id', v)} placeholder={t('common.select')} options={options} error={err('to_account_id')} />
        <Input label={t('ledger.amount')} required inputMode="decimal" suffix="৳" value={f.amount} onChange={(e) => set('amount', e.target.value.replace(/[^\d.]/g, ''))} error={err('amount')} />
        <Input label={t('ledger.transfers.fee')} inputMode="decimal" suffix="৳" hint={t('ledger.transfers.feeHint')} value={f.fee_amount} onChange={(e) => set('fee_amount', e.target.value.replace(/[^\d.]/g, ''))} error={err('fee_amount')} />
        <Input label={t('ledger.date')} type="date" max={today()} value={f.transfer_date} onChange={(e) => set('transfer_date', e.target.value)} error={err('transfer_date')} />
        <div className="sm:col-span-2"><Textarea label={t('ledger.note')} rows={2} value={f.note} onChange={(e) => set('note', e.target.value)} /></div>
      </div>
    </Modal>
  );
}

/** FR-1004: money moved between our own accounts. A charge is booked as an expense on the source account. */
export function TransfersPage() {
  const { t, i18n } = useTranslation();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [open, setOpen] = useState(false);
  const [voiding, setVoiding] = useState<FundTransfer | null>(null);
  const query = useQuery({ queryKey: [...LEDGER_KEYS.transfers, page, perPage], queryFn: () => transferApi.list({ page, per_page: perPage }), placeholderData: (p) => p });
  const void_ = useMutation({ mutationFn: (reason: string) => transferApi.void(voiding!.id, reason), onSuccess: () => { refresh(); toast.success(t('ledger.transfers.voided')); setVoiding(null); }, onError });
  const columns = useMemo<ColumnDef<FundTransfer, unknown>[]>(() => [
    { id: 'no', header: t('ledger.transfers.no'), cell: ({ row }) => <span className="font-semibold text-navy-900">{row.original.transfer_no}</span> },
    { id: 'date', header: t('ledger.date'), cell: ({ row }) => fmtDateTime(row.original.transfer_date, i18n.language, false) },
    { id: 'route', header: t('ledger.transfers.route'), cell: ({ row }) => <span>{row.original.from?.name} <ArrowLeftRight className="mx-1 inline h-3.5 w-3.5 text-ink-muted" aria-label={t('ledger.transfers.to')} /> {row.original.to?.name}</span> },
    { id: 'amount', header: t('ledger.amount'), cell: ({ row }) => <span className="tabular whitespace-nowrap font-semibold">{formatMoney(row.original.amount)}</span> },
    { id: 'fee', header: t('ledger.transfers.fee'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{Number(row.original.fee_amount) > 0 ? formatMoney(row.original.fee_amount) : '—'}</span> },
    { id: 'note', header: t('ledger.note'), cell: ({ row }) => <span className="text-ink-muted">{row.original.note}</span> },
    { id: 'status', header: t('common.status'), cell: ({ row }) => <Badge tone={row.original.status === 'void' ? 'neutral' : 'success'}>{t(row.original.status === 'void' ? 'ledger.void' : 'ledger.posted')}</Badge> },
    { id: 'actions', header: () => <span className="sr-only">{t('common.actions')}</span>, cell: ({ row }) => row.original.status === 'posted' ? <StopClick className="flex justify-end"><IconButton label={t('ledger.transfers.void')} onClick={() => setVoiding(row.original)}><Ban className="h-4 w-4" aria-hidden="true" /></IconButton></StopClick> : null },
  ], [t, i18n.language]);
  return (
    <>
      <PageHeader title={t('nav.ledger')} description={t('ledger.transfers.subtitle')} crumbs={[{ label: t('nav.ledger') }, { label: t('ledger.tabs.transfers') }]}
        actions={<Button leftIcon={<ArrowLeftRight className="h-4 w-4" aria-hidden="true" />} onClick={() => setOpen(true)} data-testid="add-transfer">{t('ledger.transfers.add')}</Button>} />
      <LedgerTabs />
      <DataTable columns={columns} data={query.data?.data ?? []} loading={query.isLoading} getRowId={(r) => String(r.id)} compact caption={t('ledger.tabs.transfers')} empty={<EmptyState body={t('ledger.transfers.empty')} />} />
      {query.data && <Pagination page={page} perPage={perPage} total={query.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
      {open && <TransferDialog open onClose={() => setOpen(false)} />}
      <ReasonDialog open={voiding !== null} title={t('ledger.transfers.voidTitle', { no: voiding?.transfer_no ?? '' })} body={t('ledger.transfers.voidBody')} confirmLabel={t('ledger.transfers.void')} danger loading={void_.isPending} error={void_.error} onCancel={() => setVoiding(null)} onConfirm={(r) => void_.mutate(r)} />
    </>
  );
}
