import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Badge } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { SALES_KEYS, shiftApi } from '@/features/sales/api';

/** "Shift open · since 10:02" for people who run a till; "No open shift" otherwise (PRD §6 step 1). It is read from the server, not remembered. */
export function ShiftPill() {
  const { t } = useTranslation();
  const { can } = useSession();
  const allowed = can(['shifts.open_close_own']);
  const shift = useQuery({ queryKey: SALES_KEYS.shift, queryFn: shiftApi.current, enabled: allowed, staleTime: 30_000, refetchInterval: 60_000 });
  if (!allowed) return <Badge tone="neutral">{t('nav.shiftClosed')}</Badge>;
  const s = shift.data;
  const since = s ? new Date(s.opened_at).toLocaleTimeString('en-GB', { timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit' }) : '';
  return (
    <Link to="/shifts" aria-label={s ? t('nav.shiftOpenSince', { time: since }) : t('nav.shiftClosed')} className="rounded-pill focus-visible:focus-ring-light">
      <Badge tone={s ? 'success' : 'neutral'}>{s ? t('nav.shiftOpenSince', { time: since }) : t('nav.shiftClosed')}</Badge>
    </Link>
  );
}
