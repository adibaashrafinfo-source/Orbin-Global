import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, toast } from '@/design/components';
import { useApiError } from '@/features/catalog/useApiError';
import { duesApi } from './api';
import { useRefreshMoney } from './hooks';
import type { DuesRow } from './types';

/** FR-1007: the date a customer promised to pay (or we plan to pay a supplier) and a note. Clearing the date clears both. */
export function PromiseDialog({ row, onClose }: { row: DuesRow; onClose: () => void }) {
  const { t } = useTranslation();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const [date, setDate] = useState(row.promise_date ?? '');
  const [note, setNote] = useState(row.promise_note ?? '');
  const save = useMutation({
    mutationFn: () => duesApi.promise({ party_type: row.type, party_id: row.id, promise_date: date || null, promise_note: note }),
    onSuccess: () => { refresh(); toast.success(t('common.saved')); onClose(); },
    onError,
  });
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} className="max-w-md" title={t(row.type === 'customer' ? 'ledger.dues.promiseTitle' : 'ledger.dues.planTitle', { name: row.name })}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={save.isPending} onClick={() => save.mutate()} data-testid="promise-save">{t('common.save')}</Button></>}>
      <div className="flex flex-col gap-3">
        <Input label={t('ledger.dues.promiseDate')} type="date" value={date} onChange={(e) => setDate(e.target.value)} hint={t('ledger.dues.clearHint')} />
        <Input label={t('ledger.note')} maxLength={255} value={note} disabled={date === ''} onChange={(e) => setNote(e.target.value)} />
      </div>
    </Modal>
  );
}
