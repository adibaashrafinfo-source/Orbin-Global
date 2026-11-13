import { useMutation, useQuery } from '@tanstack/react-query';
import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { Button, Card, CardBody, Input, PageHeader, Select, Textarea } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { apiMessage, serverFieldError } from '@/features/catalog/useApiError';
import { ApprovalDialog } from '@/features/sales/pos/ApprovalDialog';
import { ApiError } from '@/lib/api';
import { formatMoney, toPaisa } from '@/lib/money';
import { toast } from '@/design/components';
import { AllocationPanel } from './AllocationPanel';
import { previewManual, previewOldestFirst } from './allocation';
import { LEDGER_KEYS, newIdempotencyKey, paymentApi } from './api';
import { useAccounts, useRefreshMoney, today } from './hooks';
import { LedgerTabs } from './LedgerTabs';
import { PartyPicker, type PickedParty } from './PartyPicker';
import { PaymentRefFields } from './PaymentRefFields';
import { EMPTY_REFS, refsComplete, TYPE_OF, type Refs } from './refs';
import { PaymentSuccess } from './PaymentSuccess';
import { RecentPayments } from './RecentPayments';
import type { Method, PartyPayment, PartyType, PaymentBody } from './types';

/** FR-1002, BR-060 to BR-062: take a payment from a customer or pay a supplier, and see exactly which documents it settles before posting. */
export function ReceivePayPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const [params] = useSearchParams();
  const canReceive = can(['ledger.receive_payment']);
  const canPay = can(['ledger.pay_supplier']);
  const initialType: PartyType = params.get('type') === 'supplier' && canPay ? 'supplier' : canReceive ? 'customer' : 'supplier';
  const [type, setType] = useState<PartyType>(initialType);
  const fromUrl = params.get('id') && params.get('type') === initialType ? { type: initialType, id: Number(params.get('id')), code: params.get('code') ?? '', name: params.get('name') ?? '', balance: '0.00' } : null;
  const [party, setParty] = useState<PickedParty | null>(fromUrl);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<Method>('cash');
  const [accountId, setAccountId] = useState('');
  const [refs, setRefs] = useState<Refs>(EMPTY_REFS);
  const [date, setDate] = useState(today());
  const [note, setNote] = useState('');
  const [manual, setManual] = useState(false);
  const [typed, setTyped] = useState<Record<string, string>>({});
  const [done, setDone] = useState<PartyPayment | null>(null);
  const [approval, setApproval] = useState<{ reason: 'DUPLICATE_TRXID' | 'PIN_INVALID' | 'PIN_LOCKED'; error: string | null } | null>(null);
  const key = useRef(newIdempotencyKey());
  const refresh = useRefreshMoney();
  const accounts = useAccounts();

  const docsQuery = useQuery({ queryKey: [...LEDGER_KEYS.openDocs, type, party?.id], queryFn: () => paymentApi.openDocuments(type, party!.id), enabled: party !== null });
  const docs = useMemo(() => docsQuery.data?.documents ?? [], [docsQuery.data]);
  const shown = party ? { ...party, balance: docsQuery.data?.balance ?? party.balance } : null;
  const oldest = useMemo(() => previewOldestFirst(docs, amount), [docs, amount]);
  const manualP = useMemo(() => previewManual(docs, typed, amount), [docs, typed, amount]);
  const preview = manual ? manualP : oldest;

  const chosenAccount = useMemo(() => {
    const options = (accounts.data ?? []).filter((a) => a.is_active && a.account_type === TYPE_OF[method]);
    return options.find((a) => String(a.id) === accountId) ?? options.find((a) => a.is_default_for_method) ?? options[0];
  }, [accounts.data, accountId, method]);

  const body = (): PaymentBody => ({
    party_type: type, party_id: party!.id, method, amount, ...(chosenAccount ? { account_id: chosenAccount.id } : {}), payment_date: date,
    ...(method === 'cash' ? {} : method === 'bank_transfer' ? { bank_name: refs.bank_name.trim(), bank_ref: refs.bank_ref.trim() } : { sender_number: refs.sender_number, trx_id: refs.trx_id.trim() }),
    auto_oldest_first: !manual,
    ...(manual ? { allocations: docs.filter((d) => (typed[`${d.table}:${d.id}`] ?? '') !== '').map((d) => ({ doc_table: d.table, doc_id: d.id, amount: typed[`${d.table}:${d.id}`]! })) } : {}),
    ...(note.trim() ? { note: note.trim() } : {}),
  });

  const record = useMutation({
    mutationFn: (approvals?: { manager_pin: string; reason?: string }) => paymentApi.record({ ...body(), ...(approvals ? { approvals } : {}) }, key.current),
    onSuccess: (p) => { setDone(p); setApproval(null); refresh(); void docsQuery.refetch(); },
    onError: (e) => {
      if (e instanceof ApiError && (e.code === 'DUPLICATE_TRXID' || e.code === 'PIN_INVALID' || e.code === 'PIN_LOCKED')) { setApproval({ reason: e.code, error: e.code === 'DUPLICATE_TRXID' ? null : t(`errors.${e.code}`) }); return; }
      if (!(e instanceof ApiError && e.code === 'VALIDATION')) toast.error(apiMessage(t, e));
    },
  });

  const amountOk = amount !== '' && toPaisa(amount) > 0n;
  const splitOk = !manual || (manualP.overDue.length === 0 && !manualP.overAmount);
  const ready = party !== null && amountOk && refsComplete(method, refs) && chosenAccount !== undefined && splitOk && date <= today();
  const reset = () => { setDone(null); setAmount(''); setRefs(EMPTY_REFS); setNote(''); setTyped({}); setManual(false); setParty(null); key.current = newIdempotencyKey(); record.reset(); };
  const switchType = (v: string) => { setType(v as PartyType); setParty(null); setTyped({}); setAmount(''); };
  const fieldErr = (k: string) => serverFieldError(t, record.error, k);

  return (
    <>
      <PageHeader title={t('nav.ledger')} description={t('ledger.receive.subtitle')} crumbs={[{ label: t('nav.ledger') }, { label: t('ledger.tabs.receive') }]} />
      <LedgerTabs />
      {done ? <PaymentSuccess payment={done} onAnother={reset} /> : (
        <form noValidate className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]" onSubmit={(e) => { e.preventDefault(); if (ready) record.mutate(undefined); }}>
          <Card><CardBody className="flex flex-col gap-4">
            {canReceive && canPay && <Select label={t('ledger.direction')} value={type} onChange={switchType} options={[{ value: 'customer', label: t('ledger.receive.fromCustomer') }, { value: 'supplier', label: t('ledger.receive.toSupplier') }]} />}
            <PartyPicker type={type} value={shown} onChange={(p) => { setParty(p); setTyped({}); }} />
            <Input label={t('ledger.amount')} required inputMode="decimal" suffix="৳" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} error={fieldErr('amount')} />
            {docsQuery.data && Number(docsQuery.data.balance) > 0 && <button type="button" className="-mt-2 self-start text-sm font-semibold text-navy-800 underline-offset-2 hover:underline focus-visible:focus-ring-light" onClick={() => setAmount(docsQuery.data.balance)}>{t('ledger.fillBalance', { amount: formatMoney(docsQuery.data.balance) })}</button>}
            <PaymentRefFields method={method} onMethod={(m) => { setMethod(m); setAccountId(''); }} accountId={chosenAccount ? String(chosenAccount.id) : ''} onAccount={setAccountId} refs={refs} onRefs={setRefs} accounts={accounts.data ?? []} errors={{ trx_id: fieldErr('trx_id'), sender_number: fieldErr('sender_number'), bank_ref: fieldErr('bank_ref') }} />
            <div className="grid gap-3 sm:grid-cols-2">
              <Input label={t('ledger.paymentDate')} type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} error={fieldErr('payment_date')} />
              <div />
            </div>
            <Textarea label={t('ledger.note')} rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
            <div className="flex justify-end"><Button type="submit" variant="accent" size="lg" loading={record.isPending} disabled={!ready} data-testid="record-payment">{t(type === 'customer' ? 'ledger.recordReceipt' : 'ledger.recordPayment')}</Button></div>
          </CardBody></Card>
          <Card><CardBody>
            {party === null ? <p className="text-sm text-ink-muted">{t('ledger.pickPartyFirst')}</p> : <AllocationPanel docs={docs} loading={docsQuery.isLoading} preview={preview} manual={manual} onManual={setManual} typed={typed} onTyped={setTyped} overDue={manualP.overDue} overAmount={manualP.overAmount} />}
          </CardBody></Card>
        </form>
      )}
      <ApprovalDialog open={approval !== null} reason={approval?.reason ?? 'DUPLICATE_TRXID'} error={approval?.error ?? null} loading={record.isPending} onCancel={() => setApproval(null)} onSubmit={(pin, why) => record.mutate({ manager_pin: pin, ...(why ? { reason: why } : {}) })} />
      {can(['ledger.view']) && <RecentPayments />}
    </>
  );
}
