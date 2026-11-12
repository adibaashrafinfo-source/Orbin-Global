import { useMutation } from '@tanstack/react-query';
import { Lock } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Input, LogoPlate } from '@/design/components';
import { ApiError } from '@/lib/api';
import { authApi } from './api';
import { useLogout, useSession } from './useSession';

const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'pointerdown', 'scroll', 'touchstart'] as const;

/**
 * FR-101 idle lock: after `idle_lock_minutes` without input the screen locks; the user's own PIN unlocks it.
 * Users without a PIN are signed out instead (0 disables the lock).
 */
export function IdleLock() {
  const { t } = useTranslation();
  const { session } = useSession();
  const logout = useLogout();
  const minutes = session?.settings.idle_lock_minutes ?? 0;
  const [locked, setLocked] = useState(false);
  const [pin, setPin] = useState('');
  const lastActivity = useRef(0);
  const pinRef = useRef<HTMLInputElement>(null);
  const verify = useMutation({
    mutationFn: authApi.verifyPin,
    onSuccess: () => {
      setLocked(false);
      setPin('');
      lastActivity.current = Date.now();
    },
  });

  useEffect(() => {
    if (!session || minutes <= 0) return;
    lastActivity.current = Date.now();
    const mark = () => {
      lastActivity.current = Date.now();
    };
    ACTIVITY_EVENTS.forEach((e) => window.addEventListener(e, mark, { passive: true }));
    const timer = window.setInterval(() => {
      if (!locked && Date.now() - lastActivity.current > minutes * 60_000) {
        if (session.user.has_override_pin) setLocked(true);
        else logout.mutate();
      }
    }, 10_000);
    return () => {
      ACTIVITY_EVENTS.forEach((e) => window.removeEventListener(e, mark));
      window.clearInterval(timer);
    };
  }, [session, minutes, locked, logout]);

  useEffect(() => {
    if (locked) pinRef.current?.focus();
  }, [locked]);

  if (!locked) return null;
  const err = verify.error instanceof ApiError ? verify.error : null;
  return (
    <div role="dialog" aria-modal="true" aria-labelledby="idle-lock-title" className="fixed inset-0 z-[70] flex items-center justify-center bg-navy-950/90 p-6 backdrop-blur-sm">
      <form
        onSubmit={(e) => {
          e.preventDefault();
          verify.mutate(pin);
        }}
        className="card flex w-full max-w-sm flex-col items-center gap-5 p-8 text-center"
      >
        <LogoPlate width={180} className="shadow-none" />
        <Lock className="h-6 w-6 text-navy-600" aria-hidden="true" />
        <h2 id="idle-lock-title" className="font-heading text-xl font-bold text-navy-900">
          {t('auth.locked')}
        </h2>
        <p className="text-13 text-ink-muted">{t('auth.lockedBody')}</p>
        <Input label={t('auth.pin')} type="password" inputMode="numeric" pattern="\d{4,6}" ref={pinRef} value={pin} onChange={(e) => setPin(e.target.value)} error={err ? t(`errors.${err.code}`, { defaultValue: t('errors.unknown') }) : undefined} className="text-center text-lg tracking-[0.5em]" />
        <Button type="submit" variant="accent" block loading={verify.isPending}>
          {t('auth.unlock')}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={() => logout.mutate()}>
          {t('auth.signOut')}
        </Button>
      </form>
    </div>
  );
}
