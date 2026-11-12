import { format as dfFormat, formatDistanceToNowStrict, parseISO } from 'date-fns';
import { bn as bnLocale, enUS } from 'date-fns/locale';

const DHAKA_OFFSET_MINUTES = 6 * 60; // Asia/Dhaka has no DST

/** Convert a UTC ISO string to a Date shifted to Asia/Dhaka wall time for display. */
export function toDhaka(iso: string): Date {
  const d = parseISO(iso);
  return new Date(d.getTime() + (DHAKA_OFFSET_MINUTES + d.getTimezoneOffset()) * 60_000);
}

export function formatDateTime(iso: string | null | undefined, lang = 'en'): string {
  if (!iso) return '—';
  return dfFormat(toDhaka(iso), 'dd MMM yyyy, hh:mm a', { locale: lang === 'bn' ? bnLocale : enUS });
}

export function formatDate(iso: string | null | undefined, lang = 'en'): string {
  if (!iso) return '—';
  return dfFormat(toDhaka(iso), 'dd MMM yyyy', { locale: lang === 'bn' ? bnLocale : enUS });
}

export function formatRelative(iso: string | null | undefined, lang = 'en'): string {
  if (!iso) return '—';
  return formatDistanceToNowStrict(parseISO(iso), { addSuffix: true, locale: lang === 'bn' ? bnLocale : enUS });
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  const units = ['KB', 'MB', 'GB', 'TB'];
  let v = bytes / 1024;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return `${v.toFixed(1)} ${units[i]}`;
}
