import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select, Switch, Textarea, toast } from '@/design/components';
import { useLookupOptions } from '@/features/catalog/hooks';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { useDerivedState } from '@/features/catalog/util';
import { ApiError } from '@/lib/api';
import { customerApi, SALES_KEYS } from '../api';
import type { Customer, PriceTier } from '../types';

interface Form { name: string; company_name: string; phones: string; whatsapp: string; email: string; customer_type_id: string; price_tier: PriceTier; address_line1: string; address_line2: string; area: string; district: string; postcode: string; nid_no: string; trade_license_no: string; bin_no: string; credit_limit: string; opening_balance: string; notes: string; is_active: boolean }
const blank: Form = { name: '', company_name: '', phones: '', whatsapp: '', email: '', customer_type_id: '', price_tier: 'retail', address_line1: '', address_line2: '', area: '', district: '', postcode: '', nid_no: '', trade_license_no: '', bin_no: '', credit_limit: '0', opening_balance: '0', notes: '', is_active: true };
const from = (c: Customer): Form => ({ name: c.name, company_name: c.company_name ?? '', phones: c.phones.join(', '), whatsapp: c.whatsapp ?? '', email: c.email ?? '', customer_type_id: c.customer_type_id ? String(c.customer_type_id) : '', price_tier: c.price_tier, address_line1: c.address_line1 ?? '', address_line2: c.address_line2 ?? '', area: c.area ?? '', district: c.district ?? '', postcode: c.postcode ?? '', nid_no: c.nid_no ?? '', trade_license_no: c.trade_license_no ?? '', bin_no: c.bin_no ?? '', credit_limit: c.credit_limit, opening_balance: c.opening_balance, notes: c.notes ?? '', is_active: c.is_active });
const nul = (s: string) => (s.trim() === '' ? null : s.trim());
const NONE = '__none__';

/** FR-601. The opening balance is what the customer owed at go-live; it is fixed once saved because it becomes a ledger entry. */
export function CustomerDialog({ customer, canManage, onClose }: { customer: Customer | 'new' | null; canManage: boolean; onClose: (saved?: Customer) => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const types = useLookupOptions('customer_type');
  const existing = customer && customer !== 'new' ? customer : null;
  const [f, setF] = useDerivedState<Form>(existing?.id ?? customer, () => (existing ? from(existing) : blank));
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((s) => ({ ...s, [k]: v }));
  const save = useMutation({
    mutationFn: (acknowledge: boolean) => {
      const body: Record<string, unknown> = {
        name: f.name.trim(), company_name: nul(f.company_name), phones: f.phones.split(',').map((x) => x.trim()).filter(Boolean), whatsapp: nul(f.whatsapp), email: nul(f.email),
        customer_type_id: f.customer_type_id ? Number(f.customer_type_id) : null, price_tier: f.price_tier, address_line1: nul(f.address_line1), address_line2: nul(f.address_line2), area: nul(f.area),
        district: nul(f.district), postcode: nul(f.postcode), nid_no: nul(f.nid_no), trade_license_no: nul(f.trade_license_no), bin_no: nul(f.bin_no), credit_limit: f.credit_limit || '0', notes: nul(f.notes), is_active: f.is_active,
      };
      if (!existing) { body.opening_balance = f.opening_balance || '0'; if (acknowledge) body.acknowledge_duplicate = true; }
      return existing ? customerApi.update(existing.id, body) : customerApi.create(body);
    },
    onSuccess: (c) => { void client.invalidateQueries({ queryKey: SALES_KEYS.customers }); toast.success(t('common.saved')); onClose(c); },
    onError,
  });
  const dup = save.error instanceof ApiError && save.error.code === 'CUSTOMER_PHONE_EXISTS' ? (save.error.context.existing as { name: string; code: string }) : null;
  const err = (k: string) => serverFieldError(t, save.error, k);
  const dis = !canManage || existing?.is_walk_in === true;
  return (
    <Modal open={customer !== null} onOpenChange={(o) => !o && onClose()} className="max-w-3xl" title={existing ? existing.name : t('customers.add')}
      footer={<><Button variant="secondary" onClick={() => onClose()}>{t('common.cancel')}</Button>{!dis && <Button type="submit" form="customer-form" loading={save.isPending}>{t('common.save')}</Button>}</>}>
      <form id="customer-form" noValidate className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save.mutate(false); }}>
        <Input label={t('customers.fields.name')} required disabled={dis} value={f.name} onChange={(e) => set('name', e.target.value)} error={err('name')} />
        <Input label={t('customers.fields.company')} disabled={dis} value={f.company_name} onChange={(e) => set('company_name', e.target.value)} />
        <Input label={t('customers.fields.phones')} required disabled={dis} hint={t('customers.phoneHint')} value={f.phones} onChange={(e) => set('phones', e.target.value)} error={err('phones') ?? err('phones.0')} />
        <Input label={t('customers.fields.whatsapp')} disabled={dis} value={f.whatsapp} onChange={(e) => set('whatsapp', e.target.value)} />
        <Input label={t('common.email')} type="email" disabled={dis} value={f.email} onChange={(e) => set('email', e.target.value)} error={err('email')} />
        <Select label={t('customers.fields.type')} disabled={dis} value={f.customer_type_id || NONE} onChange={(v) => set('customer_type_id', v === NONE ? '' : v)} options={[{ value: NONE, label: '—' }, ...types]} />
        <Select label={t('customers.fields.tier')} disabled={dis} value={f.price_tier} onChange={(v) => set('price_tier', v as PriceTier)} options={(['retail', 'wholesale', 'dealer'] as const).map((x) => ({ value: x, label: t(`customers.tiers.${x}`) }))} />
        <Input label={t('customers.fields.creditLimit')} disabled={dis} inputMode="decimal" hint={t('customers.creditHint')} value={f.credit_limit} onChange={(e) => set('credit_limit', e.target.value)} error={err('credit_limit')} />
        <div className="sm:col-span-2"><Input label={t('customers.fields.address1')} disabled={dis} value={f.address_line1} onChange={(e) => set('address_line1', e.target.value)} /></div>
        <Input label={t('customers.fields.address2')} disabled={dis} value={f.address_line2} onChange={(e) => set('address_line2', e.target.value)} />
        <Input label={t('customers.fields.area')} disabled={dis} value={f.area} onChange={(e) => set('area', e.target.value)} />
        <Input label={t('customers.fields.district')} disabled={dis} value={f.district} onChange={(e) => set('district', e.target.value)} />
        <Input label={t('customers.fields.postcode')} disabled={dis} value={f.postcode} onChange={(e) => set('postcode', e.target.value)} />
        <Input label={t('customers.fields.bin')} disabled={dis} value={f.bin_no} onChange={(e) => set('bin_no', e.target.value)} />
        <Input label={t('customers.fields.nid')} disabled={dis} value={f.nid_no} onChange={(e) => set('nid_no', e.target.value)} />
        <Input label={t('customers.fields.license')} disabled={dis} value={f.trade_license_no} onChange={(e) => set('trade_license_no', e.target.value)} />
        <Input label={t('customers.fields.openingBalance')} hint={existing ? t('customers.openingFixed') : t('customers.openingHint')} inputMode="decimal" disabled={!!existing || dis} value={f.opening_balance} onChange={(e) => set('opening_balance', e.target.value)} error={err('opening_balance')} />
        <div className="sm:col-span-2"><Textarea label={t('stock.note')} rows={2} disabled={dis} value={f.notes} onChange={(e) => set('notes', e.target.value)} /></div>
        {existing && !existing.is_walk_in && <Switch label={t('common.active')} checked={f.is_active} disabled={dis} onChange={(v) => set('is_active', v)} />}
        {dup && (
          <div role="alert" className="rounded-card border border-orange-300 bg-orange-50 p-3 text-sm sm:col-span-2">
            <p>{t('pos.phoneExists', { name: dup.name, code: dup.code })}</p>
            <Button size="sm" variant="secondary" className="mt-2" loading={save.isPending} onClick={() => save.mutate(true)}>{t('pos.createAnyway')}</Button>
          </div>
        )}
      </form>
    </Modal>
  );
}
