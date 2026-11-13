import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Modal, Textarea } from '@/design/components';
import { serverFieldError } from '@/features/catalog/useApiError';

/** Void, reject and similar actions must say why (at least three characters) and are confirmed by a firm button. */
export function ReasonDialog({ open, title, body, confirmLabel, danger, loading, error, onCancel, onConfirm }: { open: boolean; title: string; body?: string; confirmLabel: string; danger?: boolean; loading: boolean; error: unknown; onCancel: () => void; onConfirm: (reason: string) => void }) {
  const { t } = useTranslation();
  const [reason, setReason] = useState('');
  const close = () => { setReason(''); onCancel(); };
  return (
    <Modal open={open} onOpenChange={(o) => !o && close()} className="max-w-md" title={title} description={body}
      footer={<><Button variant="secondary" onClick={close}>{t('common.cancel')}</Button><Button variant={danger ? 'danger' : 'primary'} loading={loading} disabled={reason.trim().length < 3} onClick={() => onConfirm(reason.trim())} data-testid="reason-confirm">{confirmLabel}</Button></>}>
      <Textarea label={t('ledger.reason')} required rows={3} value={reason} onChange={(e) => setReason(e.target.value)} error={serverFieldError(t, error, 'reason')} />
    </Modal>
  );
}
