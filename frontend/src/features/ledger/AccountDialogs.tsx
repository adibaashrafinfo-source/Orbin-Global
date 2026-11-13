import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select, Switch, Textarea, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { accountApi } from './api';
import { today, useRefreshMoney } from './hooks';
import type { Account, AccountType } from './types';

const TYPES: AccountType[] = ['cash', 'bkash', 'nagad', 'rocket', 'bank'];

/** FR-1003: add or edit an account. The type is fixed after creation, and the opening balance only while the account has no entries. */
export function AccountDialog({ account, open, onClose }: { account: Account | null; open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const [f, setF] = useState(() => ({
    code: account?.code ?? '', name: account?.name ?? '', account_type: account?.account_type ?? 'bank', provider: account?.provider ?? '', account_number: account?.account_number ?? '',
    bank_name: account?.bank_name ?? '', bank_branch: account?.bank_branch ?? '', opening_balance: account?.opening_balance ?? '0.00', opening_date: account?.opening_date ?? today(),
    is_default_for_method: account?.is_default_for_method ?? false, is_active: account?.is_active ?? true, note: account?.note ?? '',
  }));
  const set = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => setF((s) => ({ ...s, [k]: v }));
  const save = useMutation({
    mutationFn: () => {
      const body = { ...f, provider: f.provider || null, account_number: f.account_number || null, bank_name: f.bank_name || null, bank_branch: f.bank_branch || null, note: f.note || null };
      return account ? accountApi.update(account.id, body) : accountApi.create(body);
    },
    onSuccess: () => { refresh(); toast.success(t('common.saved')); onClose(); },
    onError,
  });
  const err = (k: string) => serverFieldError(t, save.error, k);
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-xl" title={account ? t('ledger.accounts.edit') : t('ledger.accounts.add')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={save.isPending} disabled={f.code.trim() === '' || f.name.trim() === ''} onClick={() => save.mutate()} data-testid="account-save">{t('common.save')}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Input label={t('common.code')} required value={f.code} onChange={(e) => set('code', e.target.value)} error={err('code')} />
        <Input label={t('common.name')} required value={f.name} onChange={(e) => set('name', e.target.value)} error={err('name')} />
        <Select label={t('ledger.accounts.type')} value={f.account_type} disabled={account !== null} onChange={(v) => set('account_type', v as AccountType)} options={TYPES.map((x) => ({ value: x, label: t(`ledger.accountTypes.${x}`) }))} />
        <Input label={t('ledger.accounts.number')} value={f.account_number} onChange={(e) => set('account_number', e.target.value)} />
        {f.account_type === 'bank' && (<><Input label={t('ledger.accounts.bankName')} value={f.bank_name} onChange={(e) => set('bank_name', e.target.value)} /><Input label={t('ledger.accounts.bankBranch')} value={f.bank_branch} onChange={(e) => set('bank_branch', e.target.value)} /></>)}
        <Input label={t('ledger.accounts.openingBalance')} inputMode="decimal" suffix="৳" value={f.opening_balance} onChange={(e) => set('opening_balance', e.target.value.replace(/[^\d.]/g, ''))} error={err('opening_balance')} hint={account ? t('ledger.accounts.openingHint') : undefined} />
        <Input label={t('ledger.accounts.openingDate')} type="date" value={f.opening_date} onChange={(e) => set('opening_date', e.target.value)} />
        <div className="sm:col-span-2"><Textarea label={t('ledger.note')} rows={2} value={f.note} onChange={(e) => set('note', e.target.value)} /></div>
        <Switch label={t('ledger.accounts.isDefault')} checked={f.is_default_for_method} onChange={(v) => set('is_default_for_method', v)} />
        {account && <Switch label={t('common.active')} checked={f.is_active} onChange={(v) => set('is_active', v)} />}
      </div>
    </Modal>
  );
}

/** Book a count difference or a missed entry as a dated in or out with a reason. */
export function AccountAdjustDialog({ account, open, onClose }: { account: Account; open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const [direction, setDirection] = useState<'in' | 'out'>('in');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [date, setDate] = useState(today());
  const run = useMutation({
    mutationFn: () => accountApi.adjust(account.id, { direction, amount, reason: reason.trim(), date }),
    onSuccess: () => { refresh(); toast.success(t('ledger.adjustDone')); setAmount(''); setReason(''); onClose(); },
    onError,
  });
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-md" title={t('ledger.accounts.adjustTitle', { name: account.name })} description={t('ledger.accounts.adjustBody')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={run.isPending} disabled={amount === '' || Number(amount) <= 0 || reason.trim().length < 3} onClick={() => run.mutate()} data-testid="account-adjust-confirm">{t('ledger.adjustConfirm')}</Button></>}>
      <div className="flex flex-col gap-3">
        <Select label={t('ledger.accounts.direction')} value={direction} onChange={(v) => setDirection(v as 'in' | 'out')} options={[{ value: 'in', label: t('ledger.accounts.moneyIn') }, { value: 'out', label: t('ledger.accounts.moneyOut') }]} />
        <Input label={t('ledger.amount')} required inputMode="decimal" suffix="৳" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} error={serverFieldError(t, run.error, 'amount')} />
        <Input label={t('ledger.date')} type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} />
        <Textarea label={t('ledger.reason')} required rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={serverFieldError(t, run.error, 'reason')} />
      </div>
    </Modal>
  );
}
