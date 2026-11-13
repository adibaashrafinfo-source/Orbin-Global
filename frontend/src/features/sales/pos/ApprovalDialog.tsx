import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal } from '@/design/components';

/** BR-020: a manager types their PIN to let the sale go past a limit (floor price, discount, credit, or a TrxID used before). */
export function ApprovalDialog({ open, reason, error, loading, onCancel, onSubmit }: { open: boolean; reason: 'PIN_REQUIRED' | 'DUPLICATE_TRXID' | 'PIN_INVALID' | 'PIN_LOCKED'; error: string | null; loading: boolean; onCancel: () => void; onSubmit: (pin: string, why: string) => void }) {
  const { t } = useTranslation();
  const [pin, setPin] = useState('');
  const [why, setWhy] = useState('');
  return (
    <Modal open={open} onOpenChange={(o) => { if (!o) { setPin(''); onCancel(); } }} className="max-w-sm" title={t('pos.approval.title')} description={t(`pos.approval.why.${reason}`)}
      footer={<><Button variant="secondary" onClick={onCancel}>{t('common.cancel')}</Button><Button type="submit" form="approval-form" loading={loading} disabled={pin.length < 4} leftIcon={<ShieldCheck className="h-4 w-4" aria-hidden="true" />}>{t('pos.approval.approve')}</Button></>}>
      <form id="approval-form" noValidate className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); onSubmit(pin, why); setPin(''); }}>
        <Input type="password" inputMode="numeric" autoComplete="off" label={t('pos.approval.pin')} value={pin} maxLength={8} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} error={error ?? undefined} />
        <Input label={t('pos.approval.reason')} value={why} onChange={(e) => setWhy(e.target.value)} maxLength={255} />
      </form>
    </Modal>
  );
}
