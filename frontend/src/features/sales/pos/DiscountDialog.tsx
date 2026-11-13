import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select } from '@/design/components';
import { useDerivedState } from '@/features/catalog/util';
import type { DiscountType } from '../types';

/** One dialog for a line discount (per line) and the cart discount (F8): a percentage or a fixed taka amount. */
export function DiscountDialog({ open, title, type, value, onClose, onApply }: { open: boolean; title: string; type: DiscountType; value: string; onClose: () => void; onApply: (type: DiscountType, value: string) => void }) {
  const { t } = useTranslation();
  // The key is a string so it only changes when the dialog opens or the stored discount does (an object key would reset the form on every render).
  const [f, setF] = useDerivedState(`${open}|${type}|${value}`, () => ({ type: type === 'none' ? 'percent' : type, value: value === '0' ? '' : value }));
  const apply = () => { onApply(f.value.trim() === '' || Number(f.value) === 0 ? 'none' : f.type, f.value.trim() || '0'); onClose(); };
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-sm" title={title}
      footer={<><Button variant="ghost" onClick={() => { onApply('none', '0'); onClose(); }}>{t('pos.removeDiscount')}</Button><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="discount-form">{t('common.save')}</Button></>}>
      <form id="discount-form" noValidate className="grid grid-cols-2 gap-3" onSubmit={(e) => { e.preventDefault(); apply(); }}>
        <Select label={t('pos.discountType')} value={f.type} onChange={(v) => setF({ ...f, type: v as DiscountType })} options={[{ value: 'percent', label: t('pos.percent') }, { value: 'amount', label: t('pos.amount') }]} />
        <Input label={t('pos.discountValue')} inputMode="decimal" value={f.value} onChange={(e) => setF({ ...f, value: e.target.value })} suffix={f.type === 'percent' ? '%' : '৳'} />
      </form>
    </Modal>
  );
}

/** A single amount (the delivery or handling charge, F6). */
export function AmountDialog({ open, title, label, value, onClose, onApply }: { open: boolean; title: string; label: string; value: string; onClose: () => void; onApply: (value: string) => void }) {
  const { t } = useTranslation();
  const [v, setV] = useDerivedState(open, () => (value === '0' ? '' : value));
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-xs" title={title}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="amount-form">{t('common.save')}</Button></>}>
      <form id="amount-form" noValidate onSubmit={(e) => { e.preventDefault(); onApply(v.trim() || '0'); onClose(); }}><Input label={label} inputMode="decimal" value={v} onChange={(e) => setV(e.target.value)} suffix="৳" /></form>
    </Modal>
  );
}
