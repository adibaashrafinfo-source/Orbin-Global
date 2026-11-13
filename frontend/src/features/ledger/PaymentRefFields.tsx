import { useTranslation } from 'react-i18next';
import { Input, Select } from '@/design/components';
import { METHODS, TYPE_OF, type Refs } from './refs';
import type { Account, Method } from './types';

/** The method, the account it goes into and the references that method needs. */
export function PaymentRefFields({ method, onMethod, accountId, onAccount, refs, onRefs, accounts, errors }: { method: Method; onMethod: (m: Method) => void; accountId: string; onAccount: (id: string) => void; refs: Refs; onRefs: (r: Refs) => void; accounts: Account[]; errors?: Record<string, string | undefined> }) {
  const { t } = useTranslation();
  const set = (k: keyof Refs, v: string) => onRefs({ ...refs, [k]: v });
  const options = accounts.filter((a) => a.is_active && a.account_type === TYPE_OF[method]).map((a) => ({ value: String(a.id), label: a.account_number ? `${a.name} · ${a.account_number}` : a.name }));
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Select label={t('ledger.method')} value={method} onChange={(v) => onMethod(v as Method)} options={METHODS.map((m) => ({ value: m, label: t(`pos.pay.methods.${m}`) }))} />
      {options.length > 1 ? <Select label={t('ledger.account')} value={accountId} onChange={onAccount} options={options} /> : <div />}
      {(method === 'bkash' || method === 'nagad' || method === 'rocket') && (
        <>
          <Input required label={t('pos.pay.sender')} inputMode="numeric" hint={t('pos.pay.senderHint')} maxLength={11} value={refs.sender_number} onChange={(e) => set('sender_number', e.target.value.replace(/\D/g, ''))} error={errors?.sender_number} />
          <Input required label={t('pos.pay.trxId')} value={refs.trx_id} onChange={(e) => set('trx_id', e.target.value.toUpperCase())} error={errors?.trx_id} />
        </>
      )}
      {method === 'bank_transfer' && (
        <>
          <Input required label={t('pos.pay.bankName')} value={refs.bank_name} onChange={(e) => set('bank_name', e.target.value)} error={errors?.bank_name} />
          <Input required label={t('pos.pay.bankRef')} hint={t('pos.pay.bankRefHint')} value={refs.bank_ref} onChange={(e) => set('bank_ref', e.target.value)} error={errors?.bank_ref} />
        </>
      )}
    </div>
  );
}
