import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { ArrowLeftRight, Pencil, Plus, Scale } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Badge, Button, DataTable, EmptyState, ErrorState, KpiCard, PageHeader, PageSkeleton, Pagination } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { formatMoney, fromPaisa, toPaisa } from '@/lib/money';
import { AccountCard } from './AccountCard';
import { AccountAdjustDialog, AccountDialog } from './AccountDialogs';
import { accountApi, LEDGER_KEYS } from './api';
import { useAccounts } from './hooks';
import { LedgerTabs } from './LedgerTabs';
import type { AccountTxn } from './types';

const REF_LABEL: Record<string, string> = { opening: 'opening', sale_payment: 'salePayment', sale_refund: 'saleRefund', party_payment: 'partyPayment', expense: 'expense', fund_transfer: 'fundTransfer', shift_cash_movement: 'shiftCash', adjustment: 'adjustment' };

/** FR-1003, PRD §8.9: the cash drawer, wallets and bank accounts as cards with their balance, and the ledger of the one you pick. */
export function AccountsPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const accounts = useAccounts();
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [dialog, setDialog] = useState<'new' | 'edit' | 'adjust' | null>(null);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const list = useMemo(() => accounts.data ?? [], [accounts.data]);
  const selected = list.find((a) => a.id === selectedId) ?? list[0];
  const canManage = can(['accounts.manage']);
  const canLedger = can(['ledger.view']);
  const txns = useQuery({ queryKey: [...LEDGER_KEYS.accounts, 'txns', selected?.id, page, perPage], queryFn: () => accountApi.transactions(selected!.id, { page, per_page: perPage }), enabled: selected !== undefined && canLedger, placeholderData: (p) => p });
  const total = useMemo(() => fromPaisa(list.filter((a) => a.is_active).reduce((sum, a) => sum + toPaisa(a.balance ?? '0'), 0n)), [list]);
  const columns = useMemo<ColumnDef<AccountTxn, unknown>[]>(() => [
    { id: 'date', header: t('ledger.date'), cell: ({ row }) => <span className="whitespace-nowrap">{row.original.date}</span> },
    { id: 'kind', header: t('ledger.entry'), cell: ({ row }) => <Badge tone={row.original.direction === 'in' ? 'success' : 'orange'}>{t(`ledger.refTypes.${REF_LABEL[row.original.ref_type] ?? 'adjustment'}`)}</Badge> },
    { id: 'ref', header: t('ledger.reference'), cell: ({ row }) => row.original.ref_no ?? '—' },
    { id: 'desc', header: t('ledger.description'), cell: ({ row }) => <span className="text-ink-muted">{row.original.description}</span> },
    { id: 'in', header: t('ledger.moneyIn'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{row.original.direction === 'in' ? formatMoney(row.original.amount) : ''}</span> },
    { id: 'out', header: t('ledger.moneyOut'), cell: ({ row }) => <span className="tabular whitespace-nowrap">{row.original.direction === 'out' ? formatMoney(row.original.amount) : ''}</span> },
    { id: 'balance', header: t('ledger.balance'), cell: ({ row }) => <span className="tabular whitespace-nowrap font-semibold">{formatMoney(row.original.balance)}</span> },
  ], [t]);
  if (accounts.isLoading) return <PageSkeleton />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;
  return (
    <>
      <PageHeader title={t('nav.ledger')} description={t('ledger.accounts.subtitle')} crumbs={[{ label: t('nav.ledger') }, { label: t('ledger.tabs.accounts') }]}
        actions={canManage ? <Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('new')} data-testid="add-account">{t('ledger.accounts.add')}</Button> : undefined} />
      <LedgerTabs />
      {canLedger && <div className="mb-4 max-w-xs"><KpiCard label={t('ledger.accounts.total')} value={formatMoney(total)} /></div>}
      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {list.map((a) => <AccountCard key={a.id} account={a} selected={a.id === selected?.id} onSelect={() => { setSelectedId(a.id); setPage(1); }} />)}
      </div>
      {selected && canLedger && (
        <section aria-label={t('ledger.accounts.ledgerOf', { name: selected.name })}>
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 className="font-heading text-base font-bold text-navy-900">{t('ledger.accounts.ledgerOf', { name: selected.name })}</h3>
            <div className="flex flex-wrap gap-2">
              {can(['fund_transfers.manage']) && <Link to="/ledger/transfers" className="inline-flex h-9 items-center gap-2 rounded-button border border-navy-200 px-3 text-sm font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light"><ArrowLeftRight className="h-4 w-4" aria-hidden="true" />{t('ledger.tabs.transfers')}</Link>}
              {canManage && <Button variant="secondary" size="sm" leftIcon={<Scale className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('adjust')}>{t('ledger.accounts.adjust')}</Button>}
              {canManage && <Button variant="secondary" size="sm" leftIcon={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('edit')} data-testid="edit-account">{t('common.edit')}</Button>}
            </div>
          </div>
          <DataTable columns={columns} data={txns.data?.data ?? []} loading={txns.isLoading} getRowId={(r) => String(r.id)} compact caption={t('ledger.accounts.ledgerOf', { name: selected.name })} empty={<EmptyState body={t('ledger.noEntries')} />} />
          {txns.data && <Pagination page={page} perPage={perPage} total={txns.data.meta.total} onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} />}
        </section>
      )}
      {dialog === 'new' && <AccountDialog key="new" account={null} open onClose={() => setDialog(null)} />}
      {dialog === 'edit' && selected && <AccountDialog key={selected.id} account={selected} open onClose={() => setDialog(null)} />}
      {dialog === 'adjust' && selected && <AccountAdjustDialog account={selected} open onClose={() => setDialog(null)} />}
    </>
  );
}
