import { Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button, IconButton, Input, Select } from '@/design/components';
import { EXTRA_COST_TYPES, type ExtraCost } from '../stock/types';

/** Freight, customs, clearing and the like. Leave "paid to" empty when the supplier billed the cost on their invoice. */
export function ExtraCostsEditor({ costs, onChange, disabled }: { costs: ExtraCost[]; onChange: (c: ExtraCost[]) => void; disabled?: boolean }) {
  const { t } = useTranslation();
  const set = (i: number, patch: Partial<ExtraCost>) => onChange(costs.map((c, n) => (n === i ? { ...c, ...patch } : c)));
  return (
    <div className="grid gap-3">
      {costs.length === 0 && <p className="text-sm text-ink-muted">{t('purchasing.noExtraCosts')}</p>}
      {costs.map((c, i) => (
        <div key={i} className="grid grid-cols-[11rem_1fr_8rem_1fr_auto] items-end gap-2">
          <Select aria-label={t('purchasing.costType')} disabled={disabled} value={c.cost_type} onChange={(v) => set(i, { cost_type: v })} options={EXTRA_COST_TYPES.map((x) => ({ value: x, label: t(`purchasing.costTypes.${x}`) }))} />
          <Input aria-label={t('stock.note')} placeholder={t('purchasing.costDescription')} disabled={disabled} value={c.description ?? ''} onChange={(e) => set(i, { description: e.target.value })} />
          <Input aria-label={t('purchasing.amount')} placeholder={t('purchasing.amount')} inputMode="decimal" disabled={disabled} value={c.amount} onChange={(e) => set(i, { amount: e.target.value })} />
          <Input aria-label={t('purchasing.paidTo')} placeholder={t('purchasing.paidToHint')} disabled={disabled} value={c.paid_to ?? ''} onChange={(e) => set(i, { paid_to: e.target.value })} />
          {!disabled && <IconButton label={t('common.remove')} onClick={() => onChange(costs.filter((_, n) => n !== i))}><X className="h-4 w-4" aria-hidden="true" /></IconButton>}
        </div>
      ))}
      {!disabled && <div><Button type="button" size="sm" variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => onChange([...costs, { cost_type: 'freight', description: '', amount: '', paid_to: '' }])}>{t('purchasing.addExtraCost')}</Button></div>}
    </div>
  );
}
