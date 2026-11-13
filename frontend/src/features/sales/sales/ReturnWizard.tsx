import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Checkbox, Input, Modal, Select, Textarea, toast } from '@/design/components';
import { useLookupOptions } from '@/features/catalog/hooks';
import { useApiError } from '@/features/catalog/useApiError';
import { fmtQty } from '@/features/stock/format';
import { formatMoney, fromPaisa } from '@/lib/money';
import { SALES_KEYS, returnApi } from '../api';
import { parseScaled } from '../pos/pricing';
import type { PosConfig, Sale, SaleReturn } from '../types';

interface Pick { qty: string; condition: 'resellable' | 'broken' }

/** FR-715: three steps: choose lines and quantities, say what condition and why, choose how the money goes back. */
export function ReturnWizard({ sale, config, open, onClose, onDone }: { sale: Sale; config: PosConfig | undefined; open: boolean; onClose: () => void; onDone: (r: SaleReturn) => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const reasons = useLookupOptions('return_reason');
  const [step, setStep] = useState(1);
  const [picks, setPicks] = useState<Record<number, Pick>>({});
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [mode, setMode] = useState('cash');
  const [method, setMethod] = useState('bkash');
  const items = (sale.items ?? []).filter((i) => i.bundle_parent_line === null);
  const left = (i: (typeof items)[number]) => parseScaled(i.qty, 3) - parseScaled(i.qty_returned, 3);
  const chosen = items.filter((i) => picks[i.id]);
  const bad = chosen.some((i) => { const q = parseScaled(picks[i.id]?.qty ?? '0', 3); return q <= 0n || q > left(i); });
  // A preview only: the server prices the return from what was paid for those lines.
  const estimate = chosen.reduce((a, i) => a + (parseScaled(i.line_total, 2) * parseScaled(picks[i.id]?.qty ?? '0', 3)) / parseScaled(i.qty, 3), 0n);
  const submit = useMutation({
    mutationFn: () => returnApi.create({
      sale_id: sale.id, reason_id: reason ? Number(reason) : null, note: note.trim() || null, refund_mode: mode, ...(mode === 'other_method' ? { refund_method: method } : {}),
      items: chosen.map((i) => ({ sale_item_id: i.id, qty: picks[i.id]?.qty ?? '0', condition: picks[i.id]?.condition ?? 'resellable' })),
    }),
    onSuccess: (r) => { for (const k of [SALES_KEYS.sales, SALES_KEYS.returns, ['pos', 'variants'], ['inventory']]) void client.invalidateQueries({ queryKey: k }); toast.success(t(r.status === 'posted' ? 'sales.returnPosted' : 'sales.returnPending', { no: r.return_no })); onDone(r); },
    onError,
  });
  const toggle = (id: number, on: boolean) => setPicks((p) => { const n = { ...p }; if (on) n[id] = { qty: '1', condition: 'resellable' }; else delete n[id]; return n; });
  const setPick = (id: number, patch: Partial<Pick>) => setPicks((p) => ({ ...p, [id]: { ...(p[id] ?? { qty: '1', condition: 'resellable' }), ...patch } }));
  const next = step === 1 ? chosen.length > 0 && !bad : true;
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-2xl" title={t('sales.returnTitle', { no: sale.invoice_no })} description={t('sales.returnStep', { step, total: 3 })}
      footer={<>
        {step > 1 && <Button variant="secondary" onClick={() => setStep(step - 1)}>{t('common.back')}</Button>}
        {step < 3 ? <Button disabled={!next} onClick={() => setStep(step + 1)}>{t('common.next')}</Button> : <Button loading={submit.isPending} onClick={() => submit.mutate()} data-testid="return-submit">{t('sales.returnSubmit')}</Button>}
      </>}>
      {step === 1 && (
        <ul className="divide-y divide-navy-100 rounded-card border border-navy-100" aria-label={t('sales.returnLines')}>
          {items.map((i) => (
            <li key={i.id} className="flex items-center gap-3 px-3 py-2.5">
              <Checkbox checked={!!picks[i.id]} disabled={left(i) <= 0n} onChange={(v) => toggle(i.id, v)} label={<span><span className="font-medium">{i.product_name_snapshot}</span><span className="block text-xs text-ink-muted">{i.sku_snapshot} · {t('sales.soldReturned', { sold: fmtQty(i.qty), returned: fmtQty(i.qty_returned) })}</span></span>} />
              {picks[i.id] && <div className="ms-auto w-24"><Input aria-label={t('sales.returnQty', { name: i.product_name_snapshot })} inputMode="decimal" value={picks[i.id]?.qty ?? ''} onChange={(e) => setPick(i.id, { qty: e.target.value.replace(/[^\d.]/g, '') })} error={parseScaled(picks[i.id]?.qty ?? '0', 3) > left(i) ? t('sales.tooMany') : undefined} /></div>}
            </li>
          ))}
        </ul>
      )}
      {step === 2 && (
        <div className="flex flex-col gap-3">
          {chosen.map((i) => <Select key={i.id} label={t('sales.conditionOf', { name: i.product_name_snapshot })} value={picks[i.id]?.condition ?? 'resellable'} onChange={(v) => setPick(i.id, { condition: v as Pick['condition'] })} options={[{ value: 'resellable', label: t('sales.conditions.resellable') }, { value: 'broken', label: t('sales.conditions.broken') }]} />)}
          <Select label={t('sales.reason')} value={reason || '__none__'} onChange={(v) => setReason(v === '__none__' ? '' : v)} options={[{ value: '__none__', label: '—' }, ...reasons]} />
          <Textarea label={t('stock.note')} rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
      )}
      {step === 3 && (
        <div className="flex flex-col gap-3">
          <Select label={t('sales.refundMode')} value={mode} onChange={setMode} options={['cash', 'original_method', 'other_method', 'ledger_credit'].map((m) => ({ value: m, label: t(`sales.refundModes.${m}`) }))} />
          {mode === 'other_method' && <Select label={t('sales.refundMethod')} value={method} onChange={setMethod} options={(['bkash', 'nagad', 'rocket', 'bank_transfer'] as const).filter((m) => config?.accounts.some((a) => a.account_type === (m === 'bank_transfer' ? 'bank' : m))).map((m) => ({ value: m, label: t(`pos.pay.methods.${m}`) }))} />}
          <p className="rounded-card bg-navy-50 p-3 text-sm">{t('sales.refundEstimate', { amount: formatMoney(fromPaisa(estimate)) })}<span className="block text-xs text-ink-muted">{t('sales.refundNote')}</span></p>
        </div>
      )}
    </Modal>
  );
}
