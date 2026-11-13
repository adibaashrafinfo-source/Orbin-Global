import { useTranslation } from 'react-i18next';
import { Input, Select } from '@/design/components';
import { formatMoney, fromPaisa } from '@/lib/money';
import type { FinancialAccount, PaymentMethod } from '../types';
import type { PayLine } from './checkout';
import { Keypad } from './Keypad';
import { paisa } from './payMath';

const TYPE_OF: Record<PaymentMethod, FinancialAccount['account_type']> = { cash: 'cash', bkash: 'bkash', nagad: 'nagad', rocket: 'rocket', bank_transfer: 'bank' };

/** The fields one payment method needs (FR-802, BR-060): cash gets a keypad and change; mobile money a sender and TrxID; bank a reference. */
export function PaymentForm({ line, onChange, accounts, remaining, autoFocusRef }: { line: PayLine; onChange: (l: PayLine) => void; accounts: FinancialAccount[]; remaining: bigint; autoFocusRef?: React.RefObject<HTMLInputElement> }) {
  const { t } = useTranslation();
  const set = <K extends keyof PayLine>(k: K, v: PayLine[K]) => onChange({ ...line, [k]: v });
  const options = accounts.filter((a) => a.account_type === TYPE_OF[line.method]).map((a) => ({ value: String(a.id), label: a.account_number ? `${a.name} · ${a.account_number}` : a.name }));
  const change = line.method === 'cash' && line.tendered !== '' && paisa(line.tendered) > paisa(line.amount) ? paisa(line.tendered) - paisa(line.amount) : 0n;
  const quick = [remaining, ...[500n, 1000n, 2000n, 5000n].map((n) => n * 100n)].filter((v, i, a) => v >= remaining && v > 0n && a.indexOf(v) === i).slice(0, 4);
  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-end gap-2">
        <div className="flex-1"><Input ref={autoFocusRef} label={t('pos.pay.amount')} inputMode="decimal" value={line.amount} onChange={(e) => set('amount', e.target.value.replace(/[^\d.]/g, ''))} suffix="৳" /></div>
        <button type="button" onClick={() => set('amount', fromPaisa(remaining))} className="h-10 rounded-button border border-navy-200 px-3 text-sm font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light">{t('pos.pay.fillRemaining')}</button>
      </div>
      {line.method === 'cash' && (
        <>
          <Input label={t('pos.pay.tendered')} inputMode="decimal" value={line.tendered} onChange={(e) => set('tendered', e.target.value.replace(/[^\d.]/g, ''))} suffix="৳" hint={t('pos.pay.tenderedHint')} />
          <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('pos.pay.quickCash')}>
            {quick.map((q) => <button key={String(q)} type="button" onClick={() => set('tendered', fromPaisa(q))} className="tabular rounded-pill border border-navy-200 px-3 py-1 text-sm font-medium hover:bg-navy-50 focus-visible:focus-ring-light">{q === remaining ? t('pos.pay.exact') : formatMoney(fromPaisa(q), { symbol: false })}</button>)}
          </div>
          <Keypad value={line.tendered} onChange={(v) => set('tendered', v)} />
          <div className="flex items-center justify-between rounded-card bg-navy-50 px-3 py-2 text-sm" aria-live="polite"><span className="text-ink-muted">{t('pos.pay.changeDue')}</span><span className="tabular text-lg font-extrabold text-navy-900" data-testid="change-due">{formatMoney(fromPaisa(change))}</span></div>
        </>
      )}
      {(line.method === 'bkash' || line.method === 'nagad' || line.method === 'rocket') && (
        <>
          <Input required label={t('pos.pay.sender')} inputMode="numeric" hint={t('pos.pay.senderHint')} value={line.sender_number} onChange={(e) => set('sender_number', e.target.value.replace(/[^\d]/g, ''))} maxLength={11} />
          <Input required label={t('pos.pay.trxId')} value={line.trx_id} onChange={(e) => set('trx_id', e.target.value.toUpperCase())} />
          {options.length > 1 && <Select label={t('pos.pay.receivedInto')} value={line.account_id} onChange={(v) => set('account_id', v)} options={options} />}
        </>
      )}
      {line.method === 'bank_transfer' && (
        <>
          <Input required label={t('pos.pay.bankName')} value={line.bank_name} onChange={(e) => set('bank_name', e.target.value)} />
          <Input required label={t('pos.pay.bankRef')} hint={t('pos.pay.bankRefHint')} value={line.bank_ref} onChange={(e) => set('bank_ref', e.target.value)} />
          <Input label={t('pos.pay.transferDate')} type="date" value={line.transfer_date} onChange={(e) => set('transfer_date', e.target.value)} />
          {options.length > 1 && <Select label={t('pos.pay.receivedInto')} value={line.account_id} onChange={(v) => set('account_id', v)} options={options} />}
        </>
      )}
    </div>
  );
}
