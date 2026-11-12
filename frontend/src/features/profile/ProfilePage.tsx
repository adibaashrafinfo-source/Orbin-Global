import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { Button, Card, CardBody, CardHeader, Input, PageHeader, Select, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { setLanguage } from '@/lib/i18n';
import { authApi } from '@/features/auth/api';
import { SESSION_KEY, useSession } from '@/features/auth/useSession';

const profileSchema = z.object({ full_name: z.string().trim().min(1).max(255), phone: z.string().trim().regex(/^01\d{9}$/).or(z.literal('')), language: z.enum(['en', 'bn']) });
const passwordSchema = z.object({ current_password: z.string().min(1), password: z.string().min(8), password_confirmation: z.string() }).refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'] });

/** Self-service profile: name, phone, language, password (users table access: self can edit these). */
export function ProfilePage() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const { user } = useSession();
  const profile = useForm<z.infer<typeof profileSchema>>({ resolver: zodResolver(profileSchema), defaultValues: { full_name: '', phone: '', language: 'en' } });
  const password = useForm<z.infer<typeof passwordSchema>>({ resolver: zodResolver(passwordSchema), defaultValues: { current_password: '', password: '', password_confirmation: '' } });
  useEffect(() => {
    if (user) profile.reset({ full_name: user.full_name, phone: user.phone ?? '', language: user.language });
  }, [user, profile]);
  const err = (e: unknown) => t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') });
  const saveProfile = useMutation({
    mutationFn: (v: z.infer<typeof profileSchema>) => authApi.updateProfile({ ...v, phone: v.phone || null }),
    onSuccess: (s) => { client.setQueryData(SESSION_KEY, s); setLanguage(s.user.language); toast.success(t('auth.profileSaved')); },
    onError: (e) => toast.error(err(e)),
  });
  const savePassword = useMutation({
    mutationFn: authApi.changePassword,
    onSuccess: (s) => { client.setQueryData(SESSION_KEY, s); password.reset(); toast.success(t('auth.passwordReset')); },
    onError: (e) => toast.error(err(e)),
  });
  const pwErr = savePassword.error instanceof ApiError ? savePassword.error.fields : {};
  return (
    <>
      <PageHeader title={t('auth.profile')} description={user?.email} />
      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title={t('auth.profile')} />
          <CardBody>
            <form onSubmit={profile.handleSubmit((v) => saveProfile.mutate(v))} className="grid gap-4" noValidate>
              <Input label={t('users.fullName')} required error={profile.formState.errors.full_name && t('errors.validation')} {...profile.register('full_name')} />
              <Input label={t('common.phone')} placeholder="01XXXXXXXXX" error={profile.formState.errors.phone && t('errors.validation')} {...profile.register('phone')} />
              <Select label={t('common.language')} value={profile.watch('language')} onChange={(v) => profile.setValue('language', v as 'en' | 'bn', { shouldDirty: true })} options={[{ value: 'en', label: t('common.english') }, { value: 'bn', label: t('common.bangla') }]} />
              <Button type="submit" className="self-end" loading={saveProfile.isPending} disabled={!profile.formState.isDirty}>{t('common.save')}</Button>
            </form>
          </CardBody>
        </Card>
        <Card>
          <CardHeader title={t('auth.changePassword')} />
          <CardBody>
            <form onSubmit={password.handleSubmit((v) => savePassword.mutate(v))} className="grid gap-4" noValidate>
              <Input label={t('auth.currentPassword')} type="password" autoComplete="current-password" required error={pwErr.current_password ? t('errors.AUTH_INVALID_CREDENTIALS') : password.formState.errors.current_password && t('errors.validation')} {...password.register('current_password')} />
              <Input label={t('auth.newPassword')} type="password" autoComplete="new-password" required error={password.formState.errors.password && t('errors.validation')} {...password.register('password')} />
              <Input label={t('auth.confirmPassword')} type="password" autoComplete="new-password" required error={password.formState.errors.password_confirmation && t('errors.validation')} {...password.register('password_confirmation')} />
              <Button type="submit" variant="secondary" className="self-end" loading={savePassword.isPending}>{t('auth.changePassword')}</Button>
            </form>
          </CardBody>
        </Card>
      </div>
    </>
  );
}
