import { useQuery } from '@tanstack/react-query';
import { AnimatePresence, motion } from 'framer-motion';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation } from 'react-router-dom';
import { pageVariants } from '@/design/motion';
import { useMotionSafe } from '@/design/hooks/useMotionSafe';
import { useSession } from '@/features/auth/useSession';
import { IdleLock } from '@/features/auth/IdleLock';
import { companyApi, COMPANY_KEY } from '@/features/settings/company/api';
import { branchesApi, BRANCHES_KEY } from '@/features/settings/branches/api';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

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
  const toggle = () => {
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

  return (
    <div className="min-h-screen bg-background">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-button focus:bg-surface focus:px-3 focus:py-2 focus:text-sm focus:shadow-md">
        {t('nav.skipToContent')}
      </a>
      <Sidebar collapsed={collapsed} onToggle={toggle} logoUrl={company.data?.logo_url} />
      <div className="flex min-h-screen flex-col transition-[padding] duration-base ease-standard motion-reduce:transition-none" style={{ paddingLeft: collapsed ? 72 : 264 }}>
        <Topbar branchName={branchName} />
        <main id="main" className="flex-1 px-6 py-6 lg:px-8" tabIndex={-1}>
          <AnimatePresence mode="wait" initial={false}>
            <motion.div key={location.pathname} variants={variants} initial="initial" animate="animate" exit="exit" className="mx-auto w-full max-w-[1400px]">
              <Outlet />
            </motion.div>
          </AnimatePresence>
        </main>
      </div>
      <IdleLock />
    </div>
  );
}
