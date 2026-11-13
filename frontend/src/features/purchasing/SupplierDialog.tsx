import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select, Switch, Textarea, toast } from '@/design/components';
import { serverFieldError, useApiError } from '../catalog/useApiError';
import { useDerivedState } from '../catalog/util';
import { STOCK_KEYS, supplierApi } from '../stock/api';
import type { Supplier } from '../stock/types';

interface Form { code: string; name: string; contact_person: string; phones: string; email: string; address: string; city: string; country: string; supplier_type: 'local' | 'import'; payment_terms_days: string; credit_limit: string; opening_balance: string; notes: string; is_active: boolean }

const blank: Form = { code: '', name: '', contact_person: '', phones: '', email: '', address: '', city: '', country: 'Bangladesh', supplier_type: 'local', payment_terms_days: '0', credit_limit: '0', opening_balance: '0', notes: '', is_active: true };

const fromSupplier = (s: Supplier): Form => ({ code: s.code, name: s.name, contact_person: s.contact_person ?? '', phones: s.phones.join(', '), email: s.email ?? '', address: s.address ?? '', city: s.city ?? '', country: s.country ?? '', supplier_type: s.supplier_type, payment_terms_days: String(s.payment_terms_days), credit_limit: s.credit_limit, opening_balance: s.opening_balance, notes: s.notes ?? '', is_active: s.is_active });

/** FR-501. The opening balance is what we owe at go-live; it is fixed once saved because it becomes a ledger entry. */
export function SupplierDialog({ supplier, onClose, canManage }: { supplier: Supplier | 'new' | null; onClose: () => void; canManage: boolean }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const existing = supplier && supplier !== 'new' ? supplier : null;
  const [f, setF] = useDerivedState<Form>(existing?.id ?? supplier, () => (existing ? fromSupplier(existing) : blank));
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((s) => ({ ...s, [k]: v }));
  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = {
        name: f.name.trim(), contact_person: f.contact_person.trim() || null, phones: f.phones.split(',').map((x) => x.trim()).filter(Boolean), email: f.email.trim() || null, address: f.address.trim() || null,
        city: f.city.trim() || null, country: f.country.trim() || null, supplier_type: f.supplier_type, payment_terms_days: Number(f.payment_terms_days || 0), credit_limit: f.credit_limit || '0', notes: f.notes.trim() || null, is_active: f.is_active,
      };
      if (f.code.trim()) body.code = f.code.trim();
      if (!existing) body.opening_balance = f.opening_balance || '0';
      return existing ? supplierApi.update(existing.id, body) : supplierApi.create(body);
    },
    onSuccess: () => { void client.invalidateQueries({ queryKey: STOCK_KEYS.suppliers }); toast.success(t('stock.saved')); onClose(); },
    onError,
  });
  const err = (k: string) => serverFieldError(t, save.error, k);
  return (
    <Modal open={supplier !== null} onOpenChange={(o) => !o && onClose()} className="max-w-2xl" title={existing ? existing.name : t('purchasing.addSupplier')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>{canManage && <Button type="submit" form="supplier-form" loading={save.isPending}>{t('common.save')}</Button>}</>}>
      <form id="supplier-form" noValidate className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
        <Input label={t('purchasing.fields.name')} required disabled={!canManage} value={f.name} onChange={(e) => set('name', e.target.value)} error={err('name')} />
        <Input label={t('common.code')} hint={t('purchasing.codeHint')} disabled={!canManage} value={f.code} onChange={(e) => set('code', e.target.value)} error={err('code')} />
        <Input label={t('purchasing.fields.contact')} disabled={!canManage} value={f.contact_person} onChange={(e) => set('contact_person', e.target.value)} />
        <Input label={t('purchasing.fields.phones')} hint={t('purchasing.phonesHint')} disabled={!canManage} value={f.phones} onChange={(e) => set('phones', e.target.value)} />
        <Input label={t('common.email')} type="email" disabled={!canManage} value={f.email} onChange={(e) => set('email', e.target.value)} error={err('email')} />
        <Select label={t('purchasing.fields.type')} disabled={!canManage} value={f.supplier_type} onChange={(v) => set('supplier_type', v as 'local' | 'import')} options={[{ value: 'local', label: t('purchasing.types.local') }, { value: 'import', label: t('purchasing.types.import') }]} />
        <Input label={t('purchasing.fields.city')} disabled={!canManage} value={f.city} onChange={(e) => set('city', e.target.value)} />
        <Input label={t('purchasing.fields.country')} disabled={!canManage} value={f.country} onChange={(e) => set('country', e.target.value)} />
        <div className="sm:col-span-2"><Input label={t('purchasing.fields.address')} disabled={!canManage} value={f.address} onChange={(e) => set('address', e.target.value)} /></div>
        <Input label={t('purchasing.fields.terms')} type="number" min={0} disabled={!canManage} value={f.payment_terms_days} onChange={(e) => set('payment_terms_days', e.target.value)} error={err('payment_terms_days')} />
        <Input label={t('purchasing.fields.creditLimit')} inputMode="decimal" disabled={!canManage} value={f.credit_limit} onChange={(e) => set('credit_limit', e.target.value)} error={err('credit_limit')} />
        <Input label={t('purchasing.fields.openingBalance')} hint={existing ? t('purchasing.openingFixed') : t('purchasing.openingHint')} inputMode="decimal" disabled={!!existing || !canManage} value={f.opening_balance} onChange={(e) => set('opening_balance', e.target.value)} error={err('opening_balance')} />
        {existing && <Input label={t('purchasing.fields.balance')} readOnly disabled value={existing.balance} hint={t('purchasing.balanceHint')} />}
        <div className="sm:col-span-2"><Textarea label={t('stock.note')} rows={2} disabled={!canManage} value={f.notes} onChange={(e) => set('notes', e.target.value)} /></div>
        <Switch label={t('common.active')} checked={f.is_active} disabled={!canManage} onChange={(v) => set('is_active', v)} />
      </form>
    </Modal>
  );
}
