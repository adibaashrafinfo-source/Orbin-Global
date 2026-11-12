import { useEffect } from 'react';
import { useBlocker } from 'react-router-dom';
import i18n from '@/lib/i18n';

/** Warns before leaving a dirty form (browser unload + in-app navigation). PRD §8.13. */
export function useUnsavedChangesGuard(dirty: boolean): void {
  useEffect(() => {
    if (!dirty) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [dirty]);

  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname !== nextLocation.pathname);
  useEffect(() => {
    if (blocker.state === 'blocked') {
      if (window.confirm(i18n.t('common.unsavedChanges'))) blocker.proceed();
      else blocker.reset();
    }
  }, [blocker]);
}
