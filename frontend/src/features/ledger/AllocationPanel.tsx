import { useTranslation } from 'react-i18next';
import { Badge, EmptyState, Input, Skeleton, Switch } from '@/design/components';
import { formatMoney, fromPaisa } from '@/lib/money';
import { docKey, type Preview } from './allocation';
import type { OpenDoc } from './types';

/** "Applied to": the documents the party still owes on, oldest first, with what this payment would settle on each (BR-062). Switch to manual to split it yourself. */
export function AllocationPanel({ docs, loading, preview, manual, onManual, typed, onTyped, overDue, overAmount }: { docs: OpenDoc[]; loading: boolean; preview: Preview; manual: boolean; onManual: (m: boolean) => void; typed: Record<string, string>; onTyped: (t: Record<string, string>) => void; overDue: string[]; overAmount: boolean }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-col gap-3" data-testid="allocation-panel">
      <div className="flex items-center justify-between gap-3">
        <h3 className="font-heading text-sm font-bold text-navy-900">{t('ledger.appliedTo')}</h3>
        <Switch label={t('ledger.splitManually')} checked={manual} onChange={onManual} />
      </div>
      {loading ? <Skeleton className="h-32" /> : docs.length === 0 ? <EmptyState body={t('ledger.nothingDue')} /> : (
        <table className="w-full text-sm">
          <caption className="sr-only">{t('ledger.appliedTo')}</caption>
          <thead><tr className="text-xs text-ink-muted"><th scope="col" className="py-1 text-start font-medium">{t('ledger.document')}</th><th scope="col" className="text-end font-medium">{t('ledger.stillDue')}</th><th scope="col" className="text-end font-medium">{t('ledger.thisPayment')}</th></tr></thead>
          <tbody>
            {docs.map((d) => {
              const key = docKey(d);
              const applied = preview.applied.get(key) ?? 0n;
              return (
                <tr key={key} className="border-t border-navy-100">
                  <td className="py-2"><span className="font-semibold">{d.no === 'OPENING' ? t('ledger.openingBalance') : d.no}</span><span className="block text-xs text-ink-muted">{d.date}</span></td>
                  <td className="tabular text-end">{formatMoney(d.open)}</td>
                  <td className="w-36 text-end">
                    {manual
                      ? <Input aria-label={t('ledger.amountFor', { no: d.no })} inputMode="decimal" value={typed[key] ?? ''} onChange={(e) => onTyped({ ...typed, [key]: e.target.value.replace(/[^\d.]/g, '') })} error={overDue.includes(key) ? t('errors.ALLOCATION_EXCEEDS_DUE') : undefined} />
                      : <span className={applied > 0n ? 'tabular font-semibold text-navy-900' : 'tabular text-ink-muted'} data-testid={`applied-${d.no}`}>{applied > 0n ? formatMoney(fromPaisa(applied)) : '—'}</span>}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
      <div className="flex items-center justify-between rounded-card bg-navy-50 px-3 py-2 text-sm" aria-live="polite">
        <span className="text-ink-muted">{t('ledger.keptAsAdvance')}</span>
        <span className="flex items-center gap-2"><span className="tabular font-bold text-navy-900" data-testid="advance-amount">{formatMoney(fromPaisa(preview.advance < 0n ? 0n : preview.advance))}</span>{overAmount && <Badge tone="danger">{t('errors.ALLOCATION_EXCEEDS_AMOUNT')}</Badge>}</span>
      </div>
    </div>
  );
}
