import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Modal, Textarea, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { SALES_KEYS, warrantyApi } from '../api';
import type { SaleItem } from '../types';

/** FR-717: raise a warranty claim against one sold line. */
export function ClaimDialog({ item, onClose }: { item: SaleItem | null; onClose: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const [issue, setIssue] = useState('');
  const create = useMutation({
    mutationFn: () => warrantyApi.create({ sale_item_id: item?.id, issue: issue.trim() }),
    onSuccess: (c) => { void client.invalidateQueries({ queryKey: SALES_KEYS.warranty }); toast.success(t('warranty.raised', { no: c.claim_no })); setIssue(''); onClose(); },
    onError,
  });
  return (
    <Modal open={item !== null} onOpenChange={(o) => !o && onClose()} className="max-w-md" title={t('warranty.raiseTitle')} description={item ? `${item.product_name_snapshot} · ${item.sku_snapshot}` : undefined}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={create.isPending} disabled={issue.trim().length < 3} onClick={() => create.mutate()}>{t('warranty.raise')}</Button></>}>
      <Textarea label={t('warranty.issue')} required rows={4} value={issue} onChange={(e) => setIssue(e.target.value)} error={serverFieldError(t, create.error, 'issue')} />
    </Modal>
  );
}
