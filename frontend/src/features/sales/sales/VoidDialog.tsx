import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Modal, Textarea, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { SALES_KEYS, saleApi } from '../api';
import type { Sale } from '../types';

/** FR-714: voiding needs a reason and a firm yes. Stock, payments and the ledger are reversed; the invoice stays, marked VOID. */
export function VoidDialog({ sale, open, onClose }: { sale: Sale; open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const [reason, setReason] = useState('');
  const run = useMutation({
    mutationFn: () => saleApi.void(sale.id, reason.trim()),
    onSuccess: () => { for (const k of [SALES_KEYS.sales, ['pos', 'variants'], ['inventory'], SALES_KEYS.customers, SALES_KEYS.shift]) void client.invalidateQueries({ queryKey: k }); toast.success(t('sales.voided', { no: sale.invoice_no })); setReason(''); onClose(); },
    onError,
  });
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-md" title={t('sales.voidTitle', { no: sale.invoice_no })} description={t('sales.voidBody')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button variant="danger" loading={run.isPending} disabled={reason.trim().length < 3} onClick={() => run.mutate()} data-testid="void-confirm">{t('sales.voidConfirm')}</Button></>}>
      <Textarea label={t('sales.voidReason')} required rows={3} value={reason} onChange={(e) => setReason(e.target.value)} error={serverFieldError(t, run.error, 'reason')} />
    </Modal>
  );
}
