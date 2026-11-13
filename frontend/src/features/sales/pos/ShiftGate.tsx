import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Landmark } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Card, CardBody, Input, Select, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { SALES_KEYS, shiftApi } from '../api';
import type { FinancialAccount } from '../types';

/** FR-716 step 1: with "sales need an open shift" on, the terminal asks for the opening float before it sells. */
export function ShiftGate({ accounts, canOpen }: { accounts: FinancialAccount[]; canOpen: boolean }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const cash = accounts.filter((a) => a.account_type === 'cash');
  const [account, setAccount] = useState(String(cash[0]?.id ?? ''));
  const [float, setFloat] = useState('');
  const open = useMutation({
    mutationFn: () => shiftApi.open({ cash_account_id: Number(account), opening_float: float || '0' }),
    onSuccess: () => { void client.invalidateQueries({ queryKey: SALES_KEYS.shift }); void client.invalidateQueries({ queryKey: SALES_KEYS.config }); toast.success(t('shifts.openedToast')); },
    onError,
  });
  return (
    <div className="mx-auto mt-10 max-w-md">
      <Card><CardBody>
        <div className="mb-3 flex items-center gap-2 text-navy-900"><Landmark className="h-5 w-5" aria-hidden="true" /><h2 className="font-heading text-lg font-bold">{t('shifts.openTitle')}</h2></div>
        <p className="mb-4 text-sm text-ink-muted">{canOpen ? t('shifts.openBody') : t('shifts.cannotOpen')}</p>
        {canOpen && (
          <form noValidate className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); open.mutate(); }}>
            <Select label={t('shifts.drawer')} value={account} onChange={setAccount} options={cash.map((a) => ({ value: String(a.id), label: a.name }))} error={serverFieldError(t, open.error, 'cash_account_id')} />
            <Input label={t('shifts.openingFloat')} inputMode="decimal" suffix="৳" value={float} onChange={(e) => setFloat(e.target.value.replace(/[^\d.]/g, ''))} hint={t('shifts.floatHint')} error={serverFieldError(t, open.error, 'opening_float')} />
            <Button type="submit" loading={open.isPending} disabled={account === ''}>{t('shifts.openAction')}</Button>
          </form>
        )}
      </CardBody></Card>
    </div>
  );
}
