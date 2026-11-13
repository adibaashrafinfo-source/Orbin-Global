import { useMutation, useQuery } from '@tanstack/react-query';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Button, EmptyState, Input, Modal, Skeleton, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { customerApi, SALES_KEYS } from '../api';
import type { Customer } from '../types';
import type { CartCustomer } from './cartStore';
import { toCartCustomer } from './helpers';

/** FR-603/605: find a customer by name, phone or code, pick the walk-in, or add one with just a name and a phone. */
export function CustomerPicker({ open, onClose, onPick, walkIn }: { open: boolean; onClose: () => void; onPick: (c: CartCustomer) => void; walkIn: Customer | undefined }) {
  const { t } = useTranslation();
  const [search, setSearch] = useState('');
  const [adding, setAdding] = useState(false);
  const q = useDebouncedValue(search, 200);
  const list = useQuery({ queryKey: [...SALES_KEYS.customers, 'pick', q], queryFn: () => customerApi.list({ search: q, per_page: 8, 'filter[is_active]': 1 }), enabled: open, placeholderData: (p) => p });
  const rows = (list.data?.data ?? []).filter((c) => !c.is_walk_in);
  const done = (c: CartCustomer) => { onPick(c); setSearch(''); setAdding(false); onClose(); };
  return (
    <Modal open={open} onOpenChange={(o) => { if (!o) { setAdding(false); onClose(); } }} className="max-w-xl" title={adding ? t('pos.newCustomer') : t('pos.chooseCustomer')}>
      {adding ? <QuickAdd initialPhone={/^\d[\d\s-]*$/.test(search) ? search : ''} onBack={() => setAdding(false)} onDone={(c) => done(toCartCustomer(c))} /> : (
        <div className="flex flex-col gap-3">
          <Input type="search" label={t('pos.searchCustomer')} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('pos.searchCustomerHint')} />
          <div className="flex gap-2">
            {walkIn && <Button variant="secondary" size="sm" onClick={() => done(toCartCustomer(walkIn))}>{t('pos.walkIn')}</Button>}
            <Button variant="secondary" size="sm" leftIcon={<UserPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAdding(true)}>{t('pos.newCustomer')}</Button>
          </div>
          {list.isLoading ? <Skeleton className="h-24" /> : rows.length === 0 ? <EmptyState body={t('pos.noCustomers')} /> : (
            <ul className="divide-y divide-navy-100 rounded-card border border-navy-100" aria-label={t('pos.customers')}>
              {rows.map((c) => (
                <li key={c.id}>
                  <button type="button" onClick={() => done(toCartCustomer(c))} className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-start hover:bg-navy-50 focus-visible:focus-ring-light">
                    <span><span className="block font-medium text-navy-900">{c.name}{c.company_name ? <span className="font-normal text-ink-muted"> · {c.company_name}</span> : null}</span><span className="text-xs text-ink-muted">{c.code} · {c.phones[0] ?? ''}</span></span>
                    <span className="text-end text-xs"><span className="block text-ink-muted">{t(`customers.tiers.${c.price_tier}`)}</span>{Number(c.balance) > 0 && <span className="font-semibold text-orange-700">{t('pos.owes', { amount: formatMoney(c.balance) })}</span>}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Modal>
  );
}

function QuickAdd({ initialPhone, onBack, onDone }: { initialPhone: string; onBack: () => void; onDone: (c: Customer) => void }) {
  const { t } = useTranslation();
  const onError = useApiError();
  const [name, setName] = useState('');
  const [phone, setPhone] = useState(initialPhone.replace(/\D/g, ''));
  const [existing, setExisting] = useState<{ id: number; code: string; name: string } | null>(null);
  const create = useMutation({
    mutationFn: (acknowledge: boolean) => customerApi.create({ name: name.trim(), phones: [phone], ...(acknowledge ? { acknowledge_duplicate: true } : {}) }),
    onSuccess: (c) => { toast.success(t('pos.customerAdded', { name: c.name })); onDone(c); },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'CUSTOMER_PHONE_EXISTS') setExisting(e.context.existing as { id: number; code: string; name: string });
      else onError(e);
    },
  });
  const useExisting = useMutation({ mutationFn: (id: number) => customerApi.show(id), onSuccess: onDone, onError });
  return (
    <form noValidate className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); create.mutate(false); }}>
      <Input required label={t('customers.fields.name')} value={name} onChange={(e) => setName(e.target.value)} error={serverFieldError(t, create.error, 'name')} />
      <Input required inputMode="numeric" label={t('customers.fields.phone')} hint={t('customers.phoneHint')} value={phone} onChange={(e) => { setPhone(e.target.value); setExisting(null); }} error={serverFieldError(t, create.error, 'phones.0')} />
      {existing && (
        <div role="alert" className="rounded-card border border-orange-300 bg-orange-50 p-3 text-sm">
          <p>{t('pos.phoneExists', { name: existing.name, code: existing.code })}</p>
          <div className="mt-2 flex gap-2"><Button size="sm" loading={useExisting.isPending} onClick={() => useExisting.mutate(existing.id)}>{t('pos.useExisting')}</Button><Button size="sm" variant="secondary" loading={create.isPending} onClick={() => create.mutate(true)}>{t('pos.createAnyway')}</Button></div>
        </div>
      )}
      <div className="flex justify-end gap-2"><Button variant="secondary" onClick={onBack}>{t('common.back')}</Button><Button type="submit" loading={create.isPending} disabled={name.trim() === '' || phone === ''}>{t('pos.addAndSelect')}</Button></div>
    </form>
  );
}
