import { zodResolver } from '@hookform/resolvers/zod';
import { motion, useReducedMotion } from 'framer-motion';
import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useLocation, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { Button, Checkbox, Input, LogoPlate } from '@/design/components';
import { ApiError } from '@/lib/api';
import { useLogin, useSession } from './useSession';
import { ForgotPasswordForm, ResetPasswordForm } from './PasswordForms';
import { LanguageToggle } from '@/app/layout/LanguageToggle';

const schema = z.object({
  email: z.string().email(),
  password: z.string().min(1),
  remember: z.boolean(),
});
type FormValues = z.infer<typeof schema>;

/** PRD §8.1: split layout, navy gradient hero with logo plate, white form, accent Sign in, error shake. */
export function LoginPage() {
  const { t } = useTranslation();
  const { user, loading } = useSession();
  const login = useLogin();
  const location = useLocation();
  const [params] = useSearchParams();
  const [showPassword, setShowPassword] = useState(false);
  const [mode, setMode] = useState<'login' | 'forgot'>('login');
  const reduced = useReducedMotion();
  const resetToken = params.get('reset');

  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '', remember: true } });

  if (!loading && user) {
    const to = (location.state as { from?: string } | null)?.from ?? '/dashboard';
    return <Navigate to={to} replace />;
  }

  const apiError = login.error instanceof ApiError ? login.error : null;
  const errorMessage = apiError ? t(`errors.${apiError.code}`, { defaultValue: t('errors.unknown') }) : null;

  return (
    <div className="flex min-h-screen bg-background">
      <section className="relative hidden w-[55%] flex-col items-center justify-center overflow-hidden bg-gradient-to-br from-navy-950 to-navy-900 p-12 lg:flex" aria-hidden="true">
        {!reduced && (
          <>
            <div className="pointer-events-none absolute -left-32 top-1/4 h-[520px] w-[520px] rotate-12 rounded-pill bg-orange-500/10 blur-3xl animate-pulse-glow" />
            <div className="pointer-events-none absolute -right-40 bottom-0 h-[600px] w-[600px] -rotate-12 rounded-pill bg-navy-500/30 blur-3xl animate-pulse-glow [animation-delay:1s]" />
          </>
        )}
        <LogoPlate ambient width={300} />
        <p className="mt-8 font-heading text-lg font-semibold tracking-wide text-navy-200">{t('app.tagline')}</p>
      </section>

      <section className="flex flex-1 flex-col">
        <div className="flex justify-end p-4">
          <LanguageToggle />
        </div>
        <div className="flex flex-1 items-center justify-center px-6 pb-12">
          <div className="w-full max-w-sm">
            <div className="mb-8 lg:hidden">
              <LogoPlate width={200} className="shadow-sm" />
            </div>
            {resetToken ? (
              <ResetPasswordForm token={resetToken} email={params.get('email') ?? ''} />
            ) : mode === 'forgot' ? (
              <ForgotPasswordForm onBack={() => setMode('login')} />
            ) : (
              <motion.form
                key={login.failureCount}
                animate={apiError && !reduced ? { x: [0, -8, 8, -6, 6, -3, 3, 0] } : { x: 0 }}
                transition={{ duration: 0.4 }}
                onSubmit={form.handleSubmit((values) => login.mutate(values))}
                className="flex flex-col gap-5"
                noValidate
              >
                <div>
                  <h1 className="font-heading text-2xl font-bold text-navy-900">{t('auth.welcomeBack')}</h1>
                  <p className="mt-1 text-13 text-ink-muted">{t('auth.loginSubtitle')}</p>
                </div>
                <Input
                  label={t('auth.email')}
                  type="email"
                  autoComplete="email"
                  required
                  error={form.formState.errors.email && t('errors.validation')}
                  {...form.register('email')}
                />
                <Input
                  label={t('auth.password')}
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  required
                  error={form.formState.errors.password && t('errors.validation')}
                  suffix={
                    <button type="button" onClick={() => setShowPassword((s) => !s)} aria-label={showPassword ? t('auth.hidePassword') : t('auth.showPassword')} className="rounded-pill p-1 text-ink-subtle hover:text-ink focus-visible:focus-ring-light">
                      {showPassword ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                    </button>
                  }
                  {...form.register('password')}
                />
                {errorMessage && (
                  <p role="alert" className="rounded-input bg-danger/10 px-3 py-2 text-13 text-danger">
                    {errorMessage}
                  </p>
                )}
                <div className="flex items-center justify-between">
                  <Checkbox checked={form.watch('remember')} onChange={(v) => form.setValue('remember', v)} label={t('auth.rememberMe')} />
                  <button type="button" onClick={() => setMode('forgot')} className="rounded text-13 font-medium text-navy-600 hover:underline focus-visible:focus-ring-light">
                    {t('auth.forgotPassword')}
                  </button>
                </div>
                <Button type="submit" variant="accent" size="lg" block loading={login.isPending}>
                  {t('auth.signIn')}
                </Button>
              </motion.form>
            )}
          </div>
        </div>
        <footer className="p-4 text-center text-xs text-ink-subtle">
          <Link to="/login" className="hover:text-ink">
            {t('auth.copyright', { year: new Date().getFullYear() })}
          </Link>
        </footer>
      </section>
    </div>
  );
}
