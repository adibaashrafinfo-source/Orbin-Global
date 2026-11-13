import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select, Textarea, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { ApiError } from '@/lib/api';
import { ledgerApi } from './api';
import { today, useRefreshMoney } from './hooks';
import type { PartyType } from './types';

interface Props {
  open: boolean;
  type: PartyType;
  partyId: number;
  onClose: () => void;
}

/** FR-1008: forgive or discount a due, oldest document first. Needs a reason and the write-off permission. */
export function WriteOffDialog({ open, type, partyId, onClose }: Props) {
  const { t } = useTranslation();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const [amount, setAmount] = useState('');
  const [kind, setKind] = useState<'writeoff' | 'discount'>('writeoff');
  const [reason, setReason] = useState('');
  const [date, setDate] = useState(today());
  const run = useMutation({
    mutationFn: () => ledgerApi.writeOff({ party_type: type, party_id: partyId, amount, kind, reason: reason.trim(), date }),
    onSuccess: () => { refresh(); toast.success(t('ledger.writeOffDone')); setAmount(''); setReason(''); onClose(); },
    onError,
  });
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-md" title={t('ledger.writeOffTitle')} description={t('ledger.writeOffBody')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={run.isPending} disabled={amount === '' || Number(amount) <= 0 || reason.trim().length < 3} onClick={() => run.mutate()} data-testid="writeoff-confirm">{t('ledger.writeOffConfirm')}</Button></>}>
      <div className="flex flex-col gap-3">
        <Select label={t('ledger.writeOffKind')} value={kind} onChange={(v) => setKind(v as 'writeoff' | 'discount')} options={[{ value: 'writeoff', label: t('ledger.kinds.writeoff') }, { value: 'discount', label: t('ledger.kinds.discount') }]} />
        <Input label={t('ledger.amount')} required inputMode="decimal" suffix="৳" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} error={serverFieldError(t, run.error, 'amount') ?? (run.error instanceof ApiError && run.error.code === 'ALLOCATION_EXCEEDS_DUE' ? t('errors.ALLOCATION_EXCEEDS_DUE') : undefined)} />
        <Input label={t('ledger.date')} type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} />
        <Textarea label={t('ledger.reason')} required rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={serverFieldError(t, run.error, 'reason')} />
      </div>
    </Modal>
  );
}

/** A correction of the balance itself (for instance a wrong opening balance), as a dated ledger entry with a reason. */
export function AdjustDialog({ open, type, partyId, onClose }: Props) {
  const { t } = useTranslation();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const [amount, setAmount] = useState('');
  const [direction, setDirection] = useState<'increase' | 'decrease'>('increase');
  const [reason, setReason] = useState('');
  const [date, setDate] = useState(today());
  const run = useMutation({
    mutationFn: () => ledgerApi.adjust({ party_type: type, party_id: partyId, direction, amount, reason: reason.trim(), date }),
    onSuccess: () => { refresh(); toast.success(t('ledger.adjustDone')); setAmount(''); setReason(''); onClose(); },
    onError,
  });
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-md" title={t('ledger.adjustTitle')} description={t('ledger.adjustBody')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={run.isPending} disabled={amount === '' || Number(amount) <= 0 || reason.trim().length < 3} onClick={() => run.mutate()} data-testid="adjust-confirm">{t('ledger.adjustConfirm')}</Button></>}>
      <div className="flex flex-col gap-3">
        <Select label={t('ledger.adjustDirection')} value={direction} onChange={(v) => setDirection(v as 'increase' | 'decrease')} options={[{ value: 'increase', label: t(type === 'customer' ? 'ledger.directions.customerIncrease' : 'ledger.directions.supplierIncrease') }, { value: 'decrease', label: t(type === 'customer' ? 'ledger.directions.customerDecrease' : 'ledger.directions.supplierDecrease') }]} />
        <Input label={t('ledger.amount')} required inputMode="decimal" suffix="৳" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} error={serverFieldError(t, run.error, 'amount')} />
        <Input label={t('ledger.date')} type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} />
        <Textarea label={t('ledger.reason')} required rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={serverFieldError(t, run.error, 'reason')} />
      </div>
    </Modal>
  );
}
