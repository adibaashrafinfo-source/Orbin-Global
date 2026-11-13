import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, ConfirmDialog, IconButton, Input, Modal, Select, Switch, Textarea, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { categoryApi, expenseApi, LEDGER_KEYS } from './api';
import { categoryName, useCategories } from './categories';
import { today, useAccounts, useRefreshMoney } from './hooks';
import type { ExpenseCategory } from './types';

/** FR-1005: money the business spends. Above the approval threshold it waits for another person and moves nothing until approved. */
export function ExpenseDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t, i18n } = useTranslation();
  const onError = useApiError();
  const refresh = useRefreshMoney();
  const categories = useCategories();
  const accounts = useAccounts();
  const [f, setF] = useState({ category_id: '', amount: '', account_id: '', expense_date: today(), payee: '', note: '' });
  const set = (k: keyof typeof f, v: string) => setF((s) => ({ ...s, [k]: v }));
  const accountOptions = (accounts.data ?? []).filter((a) => a.is_active).map((a) => ({ value: String(a.id), label: a.balance !== undefined ? `${a.name} · ${t('ledger.accounts.balanceShort', { amount: a.balance })}` : a.name }));
  const save = useMutation({
    mutationFn: () => expenseApi.create({ ...f, category_id: Number(f.category_id), account_id: Number(f.account_id) }),
    onSuccess: (e) => { refresh(); toast.success(t(e.status === 'pending_approval' ? 'ledger.expenses.pending' : 'ledger.expenses.saved', { no: e.expense_no })); setF({ category_id: '', amount: '', account_id: '', expense_date: today(), payee: '', note: '' }); onClose(); },
    onError,
  });
  const err = (k: string) => serverFieldError(t, save.error, k);
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-xl" title={t('ledger.expenses.add')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={save.isPending} disabled={f.category_id === '' || f.account_id === '' || f.amount === '' || Number(f.amount) <= 0} onClick={() => save.mutate()} data-testid="expense-save">{t('common.save')}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('ledger.expenses.category')} required value={f.category_id} onChange={(v) => set('category_id', v)} placeholder={t('common.select')} options={(categories.data ?? []).filter((c) => c.is_active).map((c) => ({ value: String(c.id), label: categoryName(c, i18n.language) }))} error={err('category_id')} />
        <Input label={t('ledger.amount')} required inputMode="decimal" suffix="৳" value={f.amount} onChange={(e) => set('amount', e.target.value.replace(/[^\d.]/g, ''))} error={err('amount')} />
        <Select label={t('ledger.expenses.paidFrom')} required value={f.account_id} onChange={(v) => set('account_id', v)} placeholder={t('common.select')} options={accountOptions} error={err('account_id')} />
        <Input label={t('ledger.date')} type="date" max={today()} value={f.expense_date} onChange={(e) => set('expense_date', e.target.value)} error={err('expense_date')} />
        <div className="sm:col-span-2"><Input label={t('ledger.expenses.payee')} value={f.payee} onChange={(e) => set('payee', e.target.value)} /></div>
        <div className="sm:col-span-2"><Textarea label={t('ledger.note')} rows={2} value={f.note} onChange={(e) => set('note', e.target.value)} /></div>
      </div>
    </Modal>
  );
}

/** Admin-managed categories (CLAUDE.md rule 9). The one transfer charges post to is protected. */
export function CategoriesDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t, i18n } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const categories = useCategories();
  const [en, setEn] = useState('');
  const [bn, setBn] = useState('');
  const [del, setDel] = useState<ExpenseCategory | null>(null);
  const done = () => { void client.invalidateQueries({ queryKey: LEDGER_KEYS.categories }); };
  const add = useMutation({ mutationFn: () => categoryApi.create({ name_en: en.trim(), name_bn: bn.trim() || null }), onSuccess: () => { done(); setEn(''); setBn(''); }, onError });
  const toggle = useMutation({ mutationFn: (c: ExpenseCategory) => categoryApi.update(c.id, { is_active: !c.is_active }), onSuccess: done, onError });
  const remove = useMutation({ mutationFn: (c: ExpenseCategory) => categoryApi.remove(c.id), onSuccess: () => { done(); setDel(null); toast.success(t('masterData.deleted')); }, onError: (e) => { setDel(null); onError(e); } });
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-xl" title={t('ledger.expenses.categories')} description={t('ledger.expenses.categoriesBody')}>
      <form noValidate className="mb-4 flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); if (en.trim()) add.mutate(); }}>
        <div className="min-w-0 flex-1"><Input label={t('ledger.expenses.nameEn')} value={en} onChange={(e) => setEn(e.target.value)} error={serverFieldError(t, add.error, 'name_en')} /></div>
        <div className="min-w-0 flex-1"><Input label={t('ledger.expenses.nameBn')} value={bn} onChange={(e) => setBn(e.target.value)} /></div>
        <Button type="submit" loading={add.isPending} disabled={en.trim() === ''}>{t('common.add')}</Button>
      </form>
      <ul className="divide-y divide-navy-100 rounded-card border border-navy-100" aria-label={t('ledger.expenses.categories')}>
        {(categories.data ?? []).map((c) => (
          <li key={c.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
            <span className="flex items-center gap-2"><span className={c.is_active ? '' : 'text-ink-muted line-through'}>{categoryName(c, i18n.language)}</span>{c.is_system && <Badge tone="navy">{t('ledger.expenses.system')}</Badge>}</span>
            <span className="flex items-center gap-2">
              <Switch label={t('common.active')} checked={c.is_active} disabled={c.is_system} onChange={() => toggle.mutate(c)} />
              {!c.is_system && <IconButton label={t('common.delete')} onClick={() => setDel(c)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>}
            </span>
          </li>
        ))}
      </ul>
      <ConfirmDialog open={del !== null} onOpenChange={(o) => !o && setDel(null)} danger title={t('ledger.expenses.deleteCategory')} body={t('ledger.expenses.deleteCategoryBody')} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (del) remove.mutate(del); }} />
    </Modal>
  );
}
