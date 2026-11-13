import { useQuery } from '@tanstack/react-query';
import { BookMinus, Download, HandCoins, Scale } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Button, Card, CardBody, EmptyState, ErrorState, Input, KpiCard, PageHeader, Select, Skeleton, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { downloadBlob } from '@/features/sales/pos/files';
import { formatMoney } from '@/lib/money';
import { ledgerApi, LEDGER_KEYS } from './api';
import { LedgerTabs } from './LedgerTabs';
import { AdjustDialog, WriteOffDialog } from './PartyAdjustDialogs';
import { PartyPicker, type PickedParty } from './PartyPicker';
import type { PartyType, StatementRow } from './types';

const REF_LINK: Record<string, (id: number) => string> = {
  sales: (id) => `/sales/${id}`,
  goods_receipts: (id) => `/purchases/receipts/${id}`,
  sale_returns: (id) => `/sales/returns/${id}`,
  purchase_returns: (id) => `/purchases/returns/${id}`,
};

function RefCell({ row }: { row: StatementRow }) {
  const to = row.ref_table && row.ref_id ? REF_LINK[row.ref_table]?.(row.ref_id) : undefined;
  if (!row.ref_no) return <span className="text-ink-muted">—</span>;
  return to ? <Link className="font-medium text-navy-800 hover:underline" to={to}>{row.ref_no}</Link> : <span>{row.ref_no}</span>;
}

/** FR-1001: every customer's and supplier's account as a statement with a running balance, printable as a PDF. */
export function PartyLedgerPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const urlType = params.get('type') === 'supplier' ? 'supplier' : 'customer';
  const [type, setType] = useState<PartyType>(urlType);
  const [party, setParty] = useState<PickedParty | null>(params.get('id') ? { type: urlType, id: Number(params.get('id')), code: params.get('code') ?? '', name: params.get('name') ?? '', balance: '0.00' } : null);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [dialog, setDialog] = useState<'writeoff' | 'adjust' | null>(null);
  const query = useQuery({ queryKey: [...LEDGER_KEYS.statement, type, party?.id, from, to], queryFn: () => ledgerApi.statement(type, party!.id, from || undefined, to || undefined), enabled: party !== null });
  const s = query.data;
  const shown = party && s ? { ...party, name: s.party.name, code: s.party.code, balance: s.party.balance } : party;
  const pdf = async () => { try { downloadBlob(await ledgerApi.statementPdf(type, party!.id, from || undefined, to || undefined), `Statement_${s?.party.code ?? 'party'}.pdf`); } catch { toast.error(t('ledger.statementFailed')); } };
  const payLink = party ? `/ledger/receive?type=${type}&id=${party.id}&code=${encodeURIComponent(s?.party.code ?? party.code)}&name=${encodeURIComponent(s?.party.name ?? party.name)}` : '';
  const canPay = can([type === 'customer' ? 'ledger.receive_payment' : 'ledger.pay_supplier']);
  return (
    <>
      <PageHeader title={t('nav.ledger')} description={t('ledger.parties.subtitle')} crumbs={[{ label: t('nav.ledger') }, { label: t('ledger.tabs.parties') }]}
        actions={party && s ? <Button variant="secondary" leftIcon={<Download className="h-4 w-4" aria-hidden="true" />} onClick={() => void pdf()}>{t('ledger.downloadStatement')}</Button> : undefined} />
      <LedgerTabs />
      <Card className="mb-5"><CardBody className="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
        <Select label={t('ledger.partyKind')} value={type} onChange={(v) => { setType(v as PartyType); setParty(null); }} options={[{ value: 'customer', label: t('ledger.customer') }, { value: 'supplier', label: t('ledger.supplier') }]} />
        <PartyPicker type={type} value={shown} onChange={setParty} />
      </CardBody></Card>
      {party === null ? <EmptyState body={t('ledger.pickPartyFirst')} /> : query.isLoading ? <Skeleton className="h-64" /> : query.isError || !s ? <ErrorState onRetry={() => void query.refetch()} /> : (
        <>
          <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard label={t('ledger.opening')} value={formatMoney(s.opening)} />
            <KpiCard label={t('ledger.totalDebit')} value={formatMoney(s.total_debit)} />
            <KpiCard label={t('ledger.totalCredit')} value={formatMoney(s.total_credit)} />
            <KpiCard label={t(type === 'customer' ? 'ledger.owesUsLabel' : 'ledger.weOweLabel')} value={formatMoney(s.closing)} />
          </div>
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <div className="w-40"><Input label={t('ledger.from')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
            <div className="w-40"><Input label={t('ledger.to')} type="date" value={to} onChange={(e) => setTo(e.target.value)} /></div>
            <div className="ms-auto flex flex-wrap gap-2">
              {canPay && <Button variant="primary" leftIcon={<HandCoins className="h-4 w-4" aria-hidden="true" />} onClick={() => navigate(payLink)}>{t(type === 'customer' ? 'ledger.receivePayment' : 'ledger.makePayment')}</Button>}
              {can(['ledger.writeoff']) && <Button variant="secondary" leftIcon={<BookMinus className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('writeoff')}>{t('ledger.writeOff')}</Button>}
              {can(['ledger.writeoff']) && <Button variant="secondary" leftIcon={<Scale className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('adjust')}>{t('ledger.adjust')}</Button>}
            </div>
          </div>
          {s.rows.length === 0 ? <EmptyState body={t('ledger.noEntries')} /> : (
            <Card><div className="overflow-x-auto"><table className="w-full text-sm" data-testid="statement-table">
              <caption className="sr-only">{t('ledger.tabs.parties')}</caption>
              <thead><tr className="text-xs text-ink-muted"><th scope="col" className="px-4 py-2 text-start font-medium">{t('ledger.date')}</th><th scope="col" className="text-start font-medium">{t('ledger.entry')}</th><th scope="col" className="text-start font-medium">{t('ledger.reference')}</th><th scope="col" className="text-start font-medium">{t('ledger.description')}</th><th scope="col" className="text-end font-medium">{t('ledger.debit')}</th><th scope="col" className="text-end font-medium">{t('ledger.credit')}</th><th scope="col" className="px-4 text-end font-medium">{t('ledger.balance')}</th></tr></thead>
              <tbody>
                <tr className="border-t border-navy-100 bg-navy-50"><td className="px-4 py-2 font-semibold" colSpan={6}>{t('ledger.openingBalance')}</td><td className="tabular px-4 text-end font-semibold">{formatMoney(s.opening)}</td></tr>
                {s.rows.map((r) => (
                  <tr key={r.id} className="border-t border-navy-100">
                    <td className="whitespace-nowrap px-4 py-2">{r.date}</td>
                    <td>{t(`customers.entryTypes.${r.type}`, { defaultValue: r.type })}</td>
                    <td><RefCell row={r} /></td>
                    <td className="max-w-xs truncate text-ink-muted" title={r.description ?? ''}>{r.description}</td>
                    <td className="tabular text-end">{Number(r.debit) > 0 ? formatMoney(r.debit) : ''}</td>
                    <td className="tabular text-end">{Number(r.credit) > 0 ? formatMoney(r.credit) : ''}</td>
                    <td className="tabular px-4 text-end font-semibold">{formatMoney(r.balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table></div></Card>
          )}
          <WriteOffDialog open={dialog === 'writeoff'} type={type} partyId={party.id} onClose={() => setDialog(null)} />
          <AdjustDialog open={dialog === 'adjust'} type={type} partyId={party.id} onClose={() => setDialog(null)} />
        </>
      )}
    </>
  );
}
