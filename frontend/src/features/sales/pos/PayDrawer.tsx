import { Banknote, Landmark, Plus, Smartphone, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Drawer, IconButton } from '@/design/components';
import { useDerivedState } from '@/features/catalog/util';
import { apiMessage } from '@/features/catalog/useApiError';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatMoney, fromPaisa } from '@/lib/money';
import type { PaymentMethod, PosConfig } from '../types';
import { useCart } from './cartStore';
import type { PayLine } from './checkout';
import { blankLine, lineProblem, paisa, payTotals } from './payMath';
import { PaymentForm } from './PaymentForm';
import type { PriceResult } from './pricing';

const METHODS: { id: PaymentMethod; icon: ReactNode }[] = [
  { id: 'cash', icon: <Banknote className="h-5 w-5" aria-hidden="true" /> },
  { id: 'bkash', icon: <Smartphone className="h-5 w-5" aria-hidden="true" /> },
  { id: 'nagad', icon: <Smartphone className="h-5 w-5" aria-hidden="true" /> },
  { id: 'rocket', icon: <Smartphone className="h-5 w-5" aria-hidden="true" /> },
  { id: 'bank_transfer', icon: <Landmark className="h-5 w-5" aria-hidden="true" /> },
];

/** FR-710/801: the payment drawer. Split payments, live remaining and change, and the one accent button of this screen: Complete Sale. */
export function PayDrawer({ open, onClose, config, totals, busy, error, onComplete }: { open: boolean; onClose: () => void; config: PosConfig; totals: PriceResult; busy: boolean; error: unknown; onComplete: (lines: PayLine[]) => void }) {
  const { t } = useTranslation();
  const customer = useCart((s) => s.customer);
  const accountFor = (m: PaymentMethod) => String(config.accounts.find((a) => a.account_type === (m === 'bank_transfer' ? 'bank' : m) && a.is_default_for_method)?.id ?? config.accounts.find((a) => a.account_type === (m === 'bank_transfer' ? 'bank' : m))?.id ?? '');
  const [lines, setLines] = useDerivedState<PayLine[]>(open, () => []);
  const [draft, setDraft] = useDerivedState<PayLine>(open, () => blankLine('cash', fromPaisa(totals.grandTotal), accountFor('cash')));
  const [tried, setTried] = useState(false);
  const amountRef = useRef<HTMLInputElement>(null);
  const named = !!customer && !customer.is_walk_in;
  const grand = totals.grandTotal;
  const draftUsable = paisa(draft.amount) > 0n;
  const effective = draftUsable ? [...lines, draft] : lines;
  const t0 = payTotals(grand, lines);
  const tot = payTotals(grand, effective);
  const over = tot.paid > grand;
  const problem = draftUsable ? lineProblem(draft) : null;
  const partial = tot.remaining > 0n;
  const walkInDue = partial && !named;
  const limit = customer && named ? paisa(customer.credit_limit) : 0n;
  const overCredit = partial && named && limit > 0n && paisa(customer?.balance ?? '0') + tot.remaining > limit;
  const canComplete = !busy && grand > 0n && problem === null && !over && !walkInDue && (effective.length > 0 || named);
  const choose = (m: PaymentMethod) => setDraft({ ...draft, method: m, account_id: accountFor(m), tendered: '', sender_number: '', trx_id: '', bank_name: '', bank_ref: '' });
  const add = () => {
    setTried(true);
    if (!draftUsable || problem !== null) return;
    const next = [...lines, draft];
    setLines(next);
    setTried(false);
    // The next payment starts empty so "Remaining" shows what is really left; "Fill remaining" puts it in with one click.
    setDraft(blankLine(draft.method, '', accountFor(draft.method)));
    amountRef.current?.focus();
  };
  const complete = () => { setTried(true); if (canComplete) onComplete(effective); };
  // Ctrl+Enter completes the sale from anywhere in the drawer (PRD §6.1). The ref keeps the listener on the latest figures.
  const completeRef = useRef(complete);
  useEffect(() => {
    completeRef.current = complete;
  });
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); completeRef.current(); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);
  const err = error instanceof ApiError && !['PIN_REQUIRED', 'PIN_INVALID', 'PIN_LOCKED', 'DUPLICATE_TRXID'].includes(error.code) ? error : null;
  return (
    <Drawer open={open} onOpenChange={(o) => !o && onClose()} title={t('pos.pay.title')} description={t('pos.pay.subtitle')}
      footer={<div className="flex w-full flex-col gap-2"><Button variant="accent" size="xl" block loading={busy} disabled={!canComplete} onClick={complete} data-testid="complete-sale">{t('pos.pay.complete')}<kbd className="ms-2 text-xs font-normal opacity-70">Ctrl+Enter</kbd></Button></div>}>
      <div className="flex flex-col gap-4">
        <dl className="rounded-card bg-navy-50 p-3 text-sm" aria-live="polite">
          <Line label={t('pos.pay.toPay')} value={formatMoney(fromPaisa(grand))} strong />
          <Line label={t('pos.pay.paid')} value={formatMoney(fromPaisa(tot.paid > grand ? grand : tot.paid))} />
          <Line label={tot.remaining > 0n ? t('pos.pay.remaining') : t('pos.pay.settled')} value={formatMoney(fromPaisa(tot.remaining))} accent={tot.remaining > 0n} testId="remaining" />
          {tot.change > 0n && <Line label={t('pos.pay.changeDue')} value={formatMoney(fromPaisa(tot.change))} />}
        </dl>
        <div role="radiogroup" aria-label={t('pos.pay.method')} className="grid grid-cols-5 gap-1.5">
          {METHODS.map((m) => (
            <button key={m.id} type="button" role="radio" aria-checked={draft.method === m.id} onClick={() => choose(m.id)}
              className={cn('flex flex-col items-center gap-1 rounded-card border-2 px-1 py-2 text-xs font-semibold transition-colors duration-fast focus-visible:focus-ring-light', draft.method === m.id ? 'border-navy-900 bg-navy-50 text-navy-900 shadow-[inset_0_-3px_0_theme(colors.orange.500)]' : 'border-navy-100 text-ink-muted hover:border-navy-300')}>
              {m.icon}<span className="whitespace-nowrap">{t(`pos.pay.methods.${m.id}`)}</span>
            </button>
          ))}
        </div>
        <PaymentForm line={draft} onChange={setDraft} accounts={config.accounts} remaining={t0.remaining} autoFocusRef={amountRef} />
        {tried && problem && <p role="alert" className="text-sm font-medium text-danger">{t(`pos.pay.err.${problem}`)}</p>}
        <Button variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} disabled={!draftUsable} onClick={add}>{t('pos.pay.addPayment')}</Button>
        {lines.length > 0 && (
          <ul aria-label={t('pos.pay.lines')} className="divide-y divide-navy-100 rounded-card border border-navy-100" data-testid="pay-lines">
            {lines.map((l) => (
              <li key={l.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                <span><span className="font-semibold">{t(`pos.pay.methods.${l.method}`)}</span><span className="block text-xs text-ink-muted">{l.trx_id || l.bank_ref || ''}</span></span>
                <span className="flex items-center gap-1"><span className="tabular font-semibold">{formatMoney(l.amount)}</span><IconButton label={t('pos.pay.removeLine')} onClick={() => setLines(lines.filter((x) => x.id !== l.id))}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton></span>
              </li>
            ))}
          </ul>
        )}
        {over && <p role="alert" className="text-sm font-medium text-danger">{t('pos.pay.overpay')}</p>}
        {walkInDue && <p role="alert" className="text-sm font-medium text-orange-700">{t('pos.pay.walkInDue')}</p>}
        {partial && named && <p className="text-sm text-ink-muted">{t('pos.pay.dueNote', { amount: formatMoney(fromPaisa(tot.remaining)), name: customer?.name ?? '' })}</p>}
        {overCredit && <p role="alert" className="text-sm font-medium text-orange-700">{t(config.settings.credit_limit_mode === 'block' ? 'pos.pay.creditBlock' : 'pos.pay.creditWarn', { limit: formatMoney(customer?.credit_limit ?? '0') })}</p>}
        {err && (
          <div role="alert" className="rounded-card border border-danger bg-surface p-3 text-sm" data-testid="checkout-error">
            <p className="font-semibold text-danger">{err.code === 'NETWORK' ? t('pos.pay.notSaved') : apiMessage(t, err)}</p>
            {err.code === 'NETWORK' && <Button className="mt-2" size="sm" onClick={complete}>{t('pos.pay.retry')}</Button>}
          </div>
        )}
      </div>
    </Drawer>
  );
}

function Line({ label, value, strong, accent, testId }: { label: string; value: string; strong?: boolean; accent?: boolean; testId?: string }) {
  return <div className="flex items-center justify-between py-0.5"><dt className="text-ink-muted">{label}</dt><dd data-testid={testId} className={cn('tabular font-medium', strong && 'text-base font-extrabold text-navy-900', accent && 'text-orange-700')}>{value}</dd></div>;
}
