import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { setLanguage, type Language } from '@/lib/i18n';
import { authApi } from '@/features/auth/api';
import { SESSION_KEY } from '@/features/auth/useSession';

/** EN | বাং toggle. Persists to the user profile when signed in. */
export function LanguageToggle({ onDark }: { onDark?: boolean }) {
  const { t, i18n } = useTranslation();
  const client = useQueryClient();
  const save = useMutation({
    mutationFn: (language: Language) => authApi.updateProfile({ language }),
    onSuccess: (s) => client.setQueryData(SESSION_KEY, s),
  });
  const current = i18n.language as Language;
  const choose = (lang: Language) => {
    setLanguage(lang);
    if (client.getQueryData(SESSION_KEY)) save.mutate(lang);
  };
  const base = 'rounded-pill px-2.5 py-1 text-xs font-semibold transition-colors duration-fast';
  return (
    <div role="group" aria-label={t('common.language')} className={cn('inline-flex rounded-pill border p-0.5', onDark ? 'border-navy-700 bg-navy-800' : 'border-navy-200 bg-surface')}>
      {(['en', 'bn'] as const).map((lang) => (
        <button
          key={lang}
          type="button"
          lang={lang}
          aria-pressed={current === lang}
          onClick={() => choose(lang)}
          className={cn(base, current === lang ? (onDark ? 'bg-surface text-navy-900' : 'bg-navy-900 text-surface') : onDark ? 'text-navy-200 hover:text-surface' : 'text-ink-muted hover:text-ink')}
        >
          {lang === 'en' ? 'EN' : 'বাং'}
        </button>
      ))}
    </div>
  );
}
