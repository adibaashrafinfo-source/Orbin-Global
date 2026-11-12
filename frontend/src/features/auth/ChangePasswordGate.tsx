import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { Button, Input, LogoPlate } from '@/design/components';
import { ApiError } from '@/lib/api';
import { authApi } from './api';
import { SESSION_KEY, useLogout } from './useSession';

const schema = z
  .object({ current_password: z.string().min(1), password: z.string().min(8), password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'] });

/** Shown when users.must_change_password is true (first login / admin reset). */
export function ChangePasswordGate() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const logout = useLogout();
  const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { current_password: '', password: '', password_confirmation: '' } });
  const change = useMutation({ mutationFn: authApi.changePassword, onSuccess: (s) => client.setQueryData(SESSION_KEY, s) });
  const err = change.error instanceof ApiError ? change.error : null;
  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-6">
      <form onSubmit={form.handleSubmit((v) => change.mutate(v))} className="card flex w-full max-w-sm flex-col gap-5 p-8" noValidate>
        <LogoPlate width={180} className="self-center shadow-none" />
        <h1 className="font-heading text-xl font-bold text-navy-900">{t('auth.changePassword')}</h1>
        <p className="text-13 text-ink-muted">{t('auth.mustChangePassword')}</p>
        <Input label={t('auth.currentPassword')} type="password" autoComplete="current-password" required error={err?.fields.current_password ? t('errors.AUTH_INVALID_CREDENTIALS') : form.formState.errors.current_password && t('errors.validation')} {...form.register('current_password')} />
        <Input label={t('auth.newPassword')} type="password" autoComplete="new-password" required error={form.formState.errors.password && t('errors.validation')} {...form.register('password')} />
        <Input label={t('auth.confirmPassword')} type="password" autoComplete="new-password" required error={form.formState.errors.password_confirmation && t('errors.validation')} {...form.register('password_confirmation')} />
        <Button type="submit" variant="accent" block loading={change.isPending}>
          {t('common.save')}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={() => logout.mutate()}>
          {t('auth.signOut')}
        </Button>
      </form>
    </div>
  );
}
