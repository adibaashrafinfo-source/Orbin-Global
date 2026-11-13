import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';
import { useSession } from '@/features/auth/useSession';
import { cn } from '@/lib/cn';

const TABS = [
  { key: 'sales', to: '/sales', end: true, any: ['sales.view_all', 'sales.view_own'] },
  { key: 'returns', to: '/sales/returns', any: ['sales.view_all', 'sales.view_own'] },
  { key: 'quotations', to: '/quotations', any: ['quotations.manage'] },
  { key: 'shifts', to: '/shifts', any: ['shifts.open_close_own', 'shifts.close_any', 'shifts.view_all'] },
  { key: 'warranty', to: '/warranty', any: ['sales.view_all'] },
] as const;

/** Tab strip shared by the sales screens (PRD §8.4). */
export function SalesTabs() {
  const { t } = useTranslation();
  const { can } = useSession();
  return (
    <nav aria-label={t('nav.sales')} className="mb-5 flex flex-wrap gap-1 border-b border-navy-100 pb-2">
      {TABS.filter((tab) => can([...tab.any])).map((tab) => (
        <NavLink key={tab.key} to={tab.to} end={'end' in tab} className={({ isActive }) => cn('rounded-input px-3 py-1.5 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light', isActive ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-100 hover:text-ink')}>{t(`sales.tabs.${tab.key}`)}</NavLink>
      ))}
    </nav>
  );
}
