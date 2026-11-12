import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { Button, ConfirmDialog, Input, Modal, Select, Switch, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { ROLES, type Role } from '@/lib/permissions';
import { useSession } from '@/features/auth/useSession';
import { branchesApi, BRANCHES_KEY } from '../branches/api';
import { usersApi, USERS_KEY, type User, type UserInput } from './api';

const schema = z.object({
  full_name: z.string().trim().min(1).max(255),
  email: z.string().trim().email().max(191),
  phone: z.string().trim().regex(/^01\d{9}$/).or(z.literal('')),
  role: z.enum(['super_admin', 'manager', 'cashier', 'storekeeper', 'accountant']),
  default_branch_id: z.number().int().nullable(),
  language: z.enum(['en', 'bn']),
  password: z.string().min(8).or(z.literal('')),
  must_change_password: z.boolean(),
  is_active: z.boolean(),
});
type Form = z.infer<typeof schema>;

function errorText(e: unknown, t: TFunction) {
  return t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') });
}

export function UserDialog({ item, onClose }: { item: User | 'new' | null; onClose: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const { user: me } = useSession();
  const branches = useQuery({ queryKey: BRANCHES_KEY, queryFn: branchesApi.list, staleTime: 5 * 60_000 });
  const isNew = item === 'new';
  const editing = item && item !== 'new' ? item : null;
  const form = useForm<Form>({ resolver: zodResolver(schema), defaultValues: { full_name: '', email: '', phone: '', role: 'cashier', default_branch_id: null, language: 'en', password: '', must_change_password: true, is_active: true } });
  const [pin, setPin] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmDeactivate, setConfirmDeactivate] = useState(false);

  useEffect(() => {
    if (isNew) form.reset({ full_name: '', email: '', phone: '', role: 'cashier', default_branch_id: branches.data?.find((b) => b.is_default)?.id ?? null, language: 'en', password: '', must_change_password: true, is_active: true });
    else if (editing) form.reset({ full_name: editing.full_name, email: editing.email, phone: editing.phone ?? '', role: editing.role, default_branch_id: editing.default_branch_id, language: editing.language, password: '', must_change_password: editing.must_change_password, is_active: editing.is_active });
    setPin('');
    setNewPassword('');
  }, [item, isNew, editing, branches.data, form]);

  const invalidate = () => void client.invalidateQueries({ queryKey: USERS_KEY });
  const save = useMutation({
    mutationFn: (v: Form) => {
      const body: UserInput = { ...v, phone: v.phone || null, branch_ids: v.default_branch_id ? [v.default_branch_id] : null, password: v.password || undefined };
      if (!isNew && editing) {
        const { password: _p, email, ...rest } = body;
        return usersApi.update(editing.id, email !== editing.email ? { ...rest, email } : rest);
      }
      return usersApi.create(body);
    },
    onSuccess: () => {
      invalidate();
      toast.success(t('users.saved'));
      onClose();
    },
    onError: (e) => toast.error(errorText(e, t)),
  });
  const resetPassword = useMutation({ mutationFn: () => usersApi.resetPassword(editing!.id, newPassword), onSuccess: () => { toast.success(t('users.passwordResetDone')); setNewPassword(''); }, onError: (e) => toast.error(errorText(e, t)) });
  const setPinM = useMutation({ mutationFn: (value: string | null) => usersApi.setPin(editing!.id, value), onSuccess: () => { invalidate(); toast.success(t('users.pinSaved')); setPin(''); }, onError: (e) => toast.error(errorText(e, t)) });
  const toggleActive = useMutation({ mutationFn: (active: boolean) => usersApi.update(editing!.id, { is_active: active }), onSuccess: () => { invalidate(); setConfirmDeactivate(false); onClose(); }, onError: (e) => toast.error(errorText(e, t)) });

  const serverErr = save.error instanceof ApiError ? save.error.fields : {};
  const err = (k: keyof Form) => (form.formState.errors[k] || serverErr[k] ? t('errors.validation') : undefined);
  const roleOptions = ROLES.filter((r) => r !== 'super_admin' || me?.role === 'super_admin').map((r) => ({ value: r, label: t(`users.roles.${r}`) }));
  const NONE = '__none__';

  return (
    <>
      <Modal
        open={item !== null}
        onOpenChange={(o) => !o && onClose()}
        title={isNew ? t('users.addUser') : t('users.editUser')}
        className="max-w-2xl"
        footer={
          <>
            {editing && editing.id !== me?.id && (
              editing.is_active ? (
                <Button variant="secondary" className="mr-auto text-danger" onClick={() => setConfirmDeactivate(true)}>{t('users.deactivate')}</Button>
              ) : (
                <Button variant="secondary" className="mr-auto" onClick={() => toggleActive.mutate(true)} loading={toggleActive.isPending}>{t('users.activate')}</Button>
              )
            )}
            <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
            <Button type="submit" form="user-form" loading={save.isPending}>{t('common.save')}</Button>
          </>
        }
      >
        <form id="user-form" onSubmit={form.handleSubmit((v) => save.mutate(v))} className="grid gap-4 md:grid-cols-2" noValidate>
          <Input label={t('users.fullName')} required error={err('full_name')} {...form.register('full_name')} />
          <Input label={t('common.email')} type="email" required error={err('email')} {...form.register('email')} />
          <Input label={t('common.phone')} placeholder="01XXXXXXXXX" error={err('phone')} {...form.register('phone')} />
          <Select label={t('common.role')} required value={form.watch('role')} onChange={(v) => form.setValue('role', v as Role)} options={roleOptions} error={err('role')} />
          <Select label={t('users.defaultBranch')} value={form.watch('default_branch_id') === null ? NONE : String(form.watch('default_branch_id'))} onChange={(v) => form.setValue('default_branch_id', v === NONE ? null : Number(v))} options={[{ value: NONE, label: t('common.none') }, ...(branches.data ?? []).map((b) => ({ value: String(b.id), label: b.name }))]} />
          <Select label={t('users.language')} value={form.watch('language')} onChange={(v) => form.setValue('language', v as 'en' | 'bn')} options={[{ value: 'en', label: t('common.english') }, { value: 'bn', label: t('common.bangla') }]} />
          {isNew && (
            <>
              <Input label={t('users.password')} type="password" autoComplete="new-password" required hint={t('users.passwordHelp')} error={err('password')} {...form.register('password')} />
              <div className="flex items-end pb-1">
                <Switch label={t('users.mustChangePassword')} checked={form.watch('must_change_password')} onChange={(v) => form.setValue('must_change_password', v)} />
              </div>
            </>
          )}
        </form>

        {editing && (
          <div className="mt-6 grid gap-4 border-t border-navy-100 pt-5 md:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Input label={t('users.resetPassword')} type="password" autoComplete="new-password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} hint={t('users.passwordHelp')} />
              <Button variant="secondary" size="sm" className="self-start" disabled={newPassword.length < 8} loading={resetPassword.isPending} onClick={() => resetPassword.mutate()}>{t('users.resetPassword')}</Button>
            </div>
            <div className="flex flex-col gap-2">
              <Input label={t('users.pin')} inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 6))} hint={t('users.pinHelp')} />
              <div className="flex gap-2">
                <Button variant="secondary" size="sm" disabled={pin.length < 4} loading={setPinM.isPending} onClick={() => setPinM.mutate(pin)}>{t('users.setPin')}</Button>
                {editing.has_override_pin && <Button variant="ghost" size="sm" onClick={() => setPinM.mutate(null)}>{t('common.remove')}</Button>}
              </div>
            </div>
          </div>
        )}
      </Modal>
      <ConfirmDialog open={confirmDeactivate} onOpenChange={setConfirmDeactivate} title={t('users.deactivate')} body={t('users.deactivateConfirm', { name: editing?.full_name ?? '' })} danger confirmLabel={t('users.deactivate')} loading={toggleActive.isPending} onConfirm={() => toggleActive.mutate(false)} />
    </>
  );
}
