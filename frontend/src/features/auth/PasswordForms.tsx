import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { Button, Input, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { authApi } from './api';

export function ForgotPasswordForm({ onBack }: { onBack: () => void }) {
  const { t } = useTranslation();
  const schema = z.object({ email: z.string().email() });
  const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { email: '' } });
  const send = useMutation({ mutationFn: (v: { email: string }) => authApi.forgotPassword(v.email) });
  return (
    <form onSubmit={form.handleSubmit((v) => send.mutate(v))} className="flex flex-col gap-5" noValidate>
      <div>
        <h1 className="font-heading text-2xl font-bold text-navy-900">{t('auth.resetPassword')}</h1>
      </div>
      {send.isSuccess ? (
        <p className="rounded-input bg-success/10 px-3 py-2 text-13 text-success">{t('auth.resetLinkSent')}</p>
      ) : (
        <Input label={t('auth.email')} type="email" required error={form.formState.errors.email && t('errors.validation')} {...form.register('email')} />
      )}
      {send.error instanceof ApiError && (
        <p role="alert" className="text-13 text-danger">
          {t(`errors.${send.error.code}`, { defaultValue: t('errors.unknown') })}
        </p>
      )}
      <div className="flex gap-2">
        <Button type="button" variant="secondary" onClick={onBack}>
          {t('auth.backToLogin')}
        </Button>
        {!send.isSuccess && (
          <Button type="submit" loading={send.isPending}>
            {t('auth.sendResetLink')}
          </Button>
        )}
      </div>
    </form>
  );
}

export function ResetPasswordForm({ token, email }: { token: string; email: string }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const schema = z
    .object({ email: z.string().email(), password: z.string().min(8), password_confirmation: z.string() })
    .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'] });
  const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { email, password: '', password_confirmation: '' } });
  const reset = useMutation({
    mutationFn: (v: z.infer<typeof schema>) => authApi.resetPassword({ ...v, token }),
    onSuccess: () => {
      toast.success(t('auth.passwordReset'));
      navigate('/login', { replace: true });
    },
  });
  return (
    <form onSubmit={form.handleSubmit((v) => reset.mutate(v))} className="flex flex-col gap-5" noValidate>
      <h1 className="font-heading text-2xl font-bold text-navy-900">{t('auth.resetPassword')}</h1>
      <Input label={t('auth.email')} type="email" required error={form.formState.errors.email && t('errors.validation')} {...form.register('email')} />
      <Input label={t('auth.newPassword')} type="password" autoComplete="new-password" required error={form.formState.errors.password && t('errors.validation')} {...form.register('password')} />
      <Input label={t('auth.confirmPassword')} type="password" autoComplete="new-password" required error={form.formState.errors.password_confirmation && t('errors.validation')} {...form.register('password_confirmation')} />
      {reset.error instanceof ApiError && (
        <p role="alert" className="text-13 text-danger">
          {t(`errors.${reset.error.code}`, { defaultValue: t('errors.unknown') })}
        </p>
      )}
      <Button type="submit" variant="accent" size="lg" block loading={reset.isPending}>
        {t('auth.resetPassword')}
      </Button>
    </form>
  );
}
