import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Play, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button, EmptyState, IconButton, Modal, Skeleton } from '@/design/components';
import { useApiError } from '@/features/catalog/useApiError';
import { saleApi, SALES_KEYS } from '../api';
import type { HeldBill } from '../types';

/** FR-707: parked bills kept on the server (F11). Resuming one hands the cart back and removes the parked copy. */
export function HeldBills({ open, onClose, onResume }: { open: boolean; onClose: () => void; onResume: (payload: Record<string, unknown>) => void }) {
  const { t, i18n } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const list = useQuery({ queryKey: SALES_KEYS.held, queryFn: saleApi.held, enabled: open });
  const resume = useMutation({ mutationFn: (b: HeldBill) => saleApi.resume(b.id), onSuccess: (p) => { void client.invalidateQueries({ queryKey: SALES_KEYS.held }); onResume(p); onClose(); }, onError });
  const discard = useMutation({ mutationFn: (b: HeldBill) => saleApi.discard(b.id), onSuccess: () => void client.invalidateQueries({ queryKey: SALES_KEYS.held }), onError });
  const time = (iso: string) => new Date(iso).toLocaleString(i18n.language === 'bn' ? 'bn-BD' : 'en-GB', { timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-lg" title={t('pos.heldTitle')} description={t('pos.heldBody')}>
      {list.isLoading ? <Skeleton className="h-20" /> : (list.data ?? []).length === 0 ? <EmptyState body={t('pos.heldEmpty')} /> : (
        <ul className="divide-y divide-navy-100 rounded-card border border-navy-100" aria-label={t('pos.heldTitle')}>
          {(list.data ?? []).map((b) => (
            <li key={b.id} className="flex items-center justify-between gap-3 px-3 py-2.5">
              <div className="min-w-0"><div className="truncate font-medium text-navy-900">{b.held_name}</div><div className="text-xs text-ink-muted">{t('pos.heldMeta', { count: b.line_count, time: time(b.held_at) })}</div></div>
              <div className="flex shrink-0 items-center gap-1"><Button size="sm" leftIcon={<Play className="h-3.5 w-3.5" aria-hidden="true" />} loading={resume.isPending} onClick={() => resume.mutate(b)}>{t('pos.resume')}</Button><IconButton label={t('pos.discardHeld', { name: b.held_name })} onClick={() => discard.mutate(b)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton></div>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  );
}
