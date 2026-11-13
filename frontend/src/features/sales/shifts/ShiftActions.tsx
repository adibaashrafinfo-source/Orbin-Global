import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Textarea, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { formatMoney, fromPaisa } from '@/lib/money';
import { SALES_KEYS, shiftApi } from '../api';
import { parseScaled } from '../pos/pricing';
import type { Shift } from '../types';

const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] as const;

/** Cash in, cash out and closing the shift against a count of notes and coins (FR-716). */
export function ShiftActions({ shift }: { shift: Shift }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const [mode, setMode] = useState<'in' | 'out' | 'close' | null>(null);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [counts, setCounts] = useState<Record<string, string>>({});
  const [note, setNote] = useState('');
  const done = () => { for (const k of [SALES_KEYS.shift, SALES_KEYS.shifts, SALES_KEYS.config]) void client.invalidateQueries({ queryKey: k }); setMode(null); setAmount(''); setReason(''); setCounts({}); setNote(''); };
  const move = useMutation({ mutationFn: () => shiftApi.cash(shift.id, { direction: mode === 'out' ? 'out' : 'in', amount, reason: reason.trim() }), onSuccess: () => { toast.success(t('shifts.moved')); done(); }, onError });
  const close = useMutation({ mutationFn: () => shiftApi.close(shift.id, { denomination_counts: Object.fromEntries(DENOMINATIONS.map((d) => [String(d), Number(counts[String(d)] || 0)])), note: note.trim() || undefined }), onSuccess: () => { toast.success(t('shifts.closedToast')); done(); }, onError });
  const counted = DENOMINATIONS.reduce((a, d) => a + BigInt(d) * 100n * BigInt(Math.max(0, Number(counts[String(d)] || 0))), 0n);
  const expected = parseScaled(shift.totals?.expected_cash ?? '0', 2);
  const diff = counted - expected;
  return (
    <>
      <div className="flex flex-wrap gap-2">
        <Button variant="secondary" onClick={() => setMode('in')}>{t('shifts.cashIn')}</Button>
        <Button variant="secondary" onClick={() => setMode('out')}>{t('shifts.cashOut')}</Button>
        <Button onClick={() => setMode('close')} data-testid="close-shift">{t('shifts.closeAction')}</Button>
      </div>
      <Modal open={mode === 'in' || mode === 'out'} onOpenChange={(o) => !o && setMode(null)} className="max-w-sm" title={mode === 'out' ? t('shifts.cashOut') : t('shifts.cashIn')}
        footer={<><Button variant="secondary" onClick={() => setMode(null)}>{t('common.cancel')}</Button><Button type="submit" form="cash-form" loading={move.isPending} disabled={amount === '' || reason.trim() === ''}>{t('common.save')}</Button></>}>
        <form id="cash-form" noValidate className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); move.mutate(); }}>
          <Input label={t('pos.pay.amount')} inputMode="decimal" suffix="৳" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} error={serverFieldError(t, move.error, 'amount')} />
          <Input label={t('shifts.reason')} required value={reason} onChange={(e) => setReason(e.target.value)} error={serverFieldError(t, move.error, 'reason')} />
        </form>
      </Modal>
      <Modal open={mode === 'close'} onOpenChange={(o) => !o && setMode(null)} className="max-w-lg" title={t('shifts.closeTitle')} description={t('shifts.closeBody', { expected: formatMoney(fromPaisa(expected)) })}
        footer={<><Button variant="secondary" onClick={() => setMode(null)}>{t('common.cancel')}</Button><Button loading={close.isPending} onClick={() => close.mutate()} data-testid="close-shift-confirm">{t('shifts.closeAction')}</Button></>}>
        <div className="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3">
          {DENOMINATIONS.map((d) => <Input key={d} label={`৳ ${d}`} inputMode="numeric" value={counts[String(d)] ?? ''} onChange={(e) => setCounts((c) => ({ ...c, [String(d)]: e.target.value.replace(/\D/g, '') }))} />)}
        </div>
        <dl className="mt-4 space-y-1 rounded-card bg-navy-50 p-3 text-sm" aria-live="polite">
          <div className="flex justify-between"><dt className="text-ink-muted">{t('shifts.counted')}</dt><dd className="tabular font-semibold">{formatMoney(fromPaisa(counted))}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-muted">{t('shifts.variance')}</dt><dd className={`tabular font-bold ${diff === 0n ? 'text-success' : 'text-danger'}`} data-testid="variance">{formatMoney(fromPaisa(diff))}</dd></div>
        </dl>
        <div className="mt-3"><Textarea label={t('stock.note')} rows={2} value={note} onChange={(e) => setNote(e.target.value)} /></div>
      </Modal>
    </>
  );
}