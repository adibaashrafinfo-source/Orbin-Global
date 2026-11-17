import { useQuery } from '@tanstack/react-query';
import { AnimatePresence, motion } from 'framer-motion';
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation } from 'react-router-dom';
import { pageVariants } from '@/design/motion';
import { cn } from '@/lib/cn';
import { useMotionSafe } from '@/design/hooks/useMotionSafe';
import { useSession } from '@/features/auth/useSession';
import { IdleLock } from '@/features/auth/IdleLock';
import { companyApi, COMPANY_KEY } from '@/features/settings/company/api';
import { branchesApi, BRANCHES_KEY } from '@/features/settings/branches/api';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { useIsDesktop } from './useIsDesktop';

const COLLAPSE_KEY = 'orbin.sidebar.collapsed';

/** App shell: sidebar stays mounted; routed pages fade + slide 8px (220ms). */
export function AppShell() {
  const { t } = useTranslation();
  const location = useLocation();
  const { user } = useSession();
  const [collapsed, setCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
      return false;
    }
  });
  // The POS is a full-screen terminal: the rail is forced to its 72 px icon width there, without touching the saved preference.
  // A click on the toggle while on the POS widens it for that visit only.
  const onPos = location.pathname === '/pos';
  const [override, setOverride] = useState<{ path: string; open: boolean } | null>(null);
  const railCollapsed = onPos ? !(override?.path === location.pathname && override.open) : collapsed;
  const toggle = () => {
    if (onPos) {
      setOverride({ path: location.pathname, open: railCollapsed });
      return;
    }
    setCollapsed((c) => {
      try {
        localStorage.setItem(COLLAPSE_KEY, c ? '0' : '1');
      } catch {
        /* ignore */
      }
      return !c;
    });
  };
  const company = useQuery({ queryKey: COMPANY_KEY, queryFn: companyApi.show, staleTime: 5 * 60_000 });
  const branches = useQuery({ queryKey: BRANCHES_KEY, queryFn: branchesApi.list, staleTime: 5 * 60_000 });
  const branchName = branches.data?.find((b) => b.id === user?.default_branch_id)?.name ?? branches.data?.find((b) => b.is_default)?.name;
  const { variants } = useMotionSafe(pageVariants);
  const desktop = useIsDesktop();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const closeDrawer = useCallback(() => setDrawerOpen(false), []);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && closeDrawer();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [closeDrawer]);

  return (
    <div className="min-h-screen bg-background">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-button focus:bg-surface focus:px-3 focus:py-2 focus:text-sm focus:shadow-md">
        {t('nav.skipToContent')}
      </a>
      {drawerOpen && <button type="button" aria-label={t('nav.closeMenu')} onClick={closeDrawer} className="fixed inset-0 z-20 bg-navy-950/40 lg:hidden" />}
      <Sidebar collapsed={desktop ? railCollapsed : false} onToggle={toggle} logoUrl={company.data?.logo_url} mobileOpen={drawerOpen} onNavigate={closeDrawer} />
      <div className={cn('flex min-h-screen flex-col transition-[padding] duration-base ease-standard motion-reduce:transition-none', railCollapsed ? 'lg:pl-[72px]' : 'lg:pl-[264px]')}>
        <Topbar branchName={branchName} menuOpen={drawerOpen} onMenu={() => setDrawerOpen((o) => !o)} />
        <main id="main" className={onPos ? 'flex-1 px-4 py-3' : 'flex-1 px-4 py-6 sm:px-6 lg:px-8'} tabIndex={-1}>
          <AnimatePresence mode="wait" initial={false}>
            <motion.div key={location.pathname} variants={variants} initial="initial" animate="animate" exit="exit" className={onPos ? 'w-full' : 'mx-auto w-full max-w-[1400px]'}>
              <Outlet />
            </motion.div>
          </AnimatePresence>
        </main>
      </div>
      <IdleLock />
    </div>
  );
}
