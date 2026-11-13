import type { TFunction } from 'i18next';
import { useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from '@/design/components';
import { ApiError } from '@/lib/api';

/** Message for an API error code, with the server's context values (sku, count, …) available as {{placeholders}}. */
export function apiMessage(t: TFunction, e: unknown): string {
  if (e instanceof ApiError) {
    const ctx = Object.fromEntries(Object.entries(e.context).map(([k, v]) => [k, typeof v === 'object' ? JSON.stringify(v) : (v as string | number)]));
    return t(`errors.${e.code}`, { ...ctx, defaultValue: t('errors.unknown') });
  }
  return t('errors.unknown');
}

/** Toast handler for failed mutations. Field-level validation errors stay on the form instead of toasting. */
export function useApiError(): (e: unknown) => void {
  const { t } = useTranslation();
  return useCallback(
    (e: unknown) => {
      if (e instanceof ApiError && e.code === 'VALIDATION') return;
      toast.error(apiMessage(t, e));
    },
    [t],
  );
}

/** First server message for a form field, translated, or undefined. */
export function serverFieldError(t: TFunction, error: unknown, key: string): string | undefined {
  if (!(error instanceof ApiError)) return undefined;
  const msg = error.fields[key]?.[0];
  if (!msg) return undefined;
  return t(msg.startsWith('errors.') ? msg : `errors.${msg}`, { defaultValue: t('errors.validation') });
}
