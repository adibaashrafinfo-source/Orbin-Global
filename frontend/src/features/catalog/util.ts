import type { TFunction } from 'i18next';
import { useState, type Dispatch, type SetStateAction } from 'react';

/** Server field errors are either an i18n key (with or without the `errors.` prefix) or plain English; unknown text falls back to the generic message. */
export function fieldMessage(t: TFunction, msg: string | undefined): string | undefined {
  if (!msg) return undefined;
  return t(msg.startsWith('errors.') ? msg : `errors.${msg}`, { defaultValue: t('errors.validation') });
}

/** Render-safe string for values typed `unknown` (master rows carry mixed JSON). Objects become the fallback. */
export function str(v: unknown, fallback = ''): string {
  if (typeof v === 'string') return v;
  if (typeof v === 'number' || typeof v === 'boolean') return String(v);
  return fallback;
}

/**
 * State that is rebuilt whenever `key` changes (for example a dialog's row). It resets during render, the pattern React
 * recommends over an effect that calls setState, so a reopened dialog never flashes the previous record.
 */
export function useDerivedState<T>(key: unknown, init: () => T): [T, Dispatch<SetStateAction<T>>] {
  const [state, setState] = useState<T>(init);
  const [seen, setSeen] = useState<unknown>(key);
  if (seen !== key) {
    setSeen(key);
    setState(init());
  }
  return [state, setState];
}
