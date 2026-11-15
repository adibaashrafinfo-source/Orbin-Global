import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select } from '@/design/components';
import type { PosConfig } from '../types';
import { useCart } from './cartStore';

const ME = '__me__';
const SALE_CHANNELS = ['counter', 'phone', 'wholesale', 'online_order'] as const;

/** FR-706: sale channel, the salesperson credited with the sale, and an invoice note. Edits go straight into the cart. */
export function SaleDetailsDialog({ open, config, onClose }: { open: boolean; config: PosConfig | undefined; onClose: () => void }) {
  const { t } = useTranslation();
  const channel = useCart((s) => s.channel);
  const salespersonId = useCart((s) => s.salespersonId);
  const note = useCart((s) => s.note);
  const setChannel = useCart((s) => s.setChannel);
  const setSalesperson = useCart((s) => s.setSalesperson);
  const setNote = useCart((s) => s.setNote);
  const people = config?.salespeople ?? [];

  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-md" title={t('pos.saleDetails')}
      footer={<Button onClick={onClose}>{t('pos.done')}</Button>}>
      <div className="grid gap-3">
        <Select label={t('pos.channel')} value={channel} onChange={setChannel}
          options={SALE_CHANNELS.map((c) => ({ value: c, label: t(`pos.channels.${c}`) }))} />
        <Select label={t('pos.salesperson')} value={salespersonId ? String(salespersonId) : ME} onChange={(v) => setSalesperson(v === ME ? null : Number(v))}
          options={[{ value: ME, label: t('pos.salespersonMe') }, ...people.map((p) => ({ value: String(p.id), label: p.full_name }))]} />
        <Input label={t('pos.invoiceNote')} hint={t('pos.invoiceNoteHint')} maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} />
      </div>
    </Modal>
  );
}
