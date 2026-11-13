import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';
import { useSession } from '@/features/auth/useSession';
import { cn } from '@/lib/cn';

const TABS = [
  { key: 'overview', to: '/inventory', end: true, any: ['inventory.view'] },
  { key: 'movements', to: '/inventory/movements', any: ['inventory.view'] },
  { key: 'adjustments', to: '/inventory/adjustments', any: ['inventory.view'] },
  { key: 'breakage', to: '/inventory/breakage', any: ['inventory.view'] },
  { key: 'transfers', to: '/inventory/transfers', any: ['inventory.view'] },
  { key: 'counts', to: '/inventory/counts', any: ['inventory.view'] },
  { key: 'reorder', to: '/inventory/reorder', any: ['inventory.view'] },
  { key: 'aging', to: '/inventory/aging', any: ['inventory.reports'] },
  { key: 'valuation', to: '/inventory/valuation', any: ['inventory.reports'], needs: ['products.view_cost'] },
] as const;

/** PRD §8.6 tab strip shared by every inventory screen. */
export function InventoryTabs() {
  const { t } = useTranslation();
  const { can } = useSession();
  return (
    <nav aria-label={t('nav.inventory')} className="mb-5 flex flex-wrap gap-1 border-b border-navy-100 pb-2">
      {TABS.filter((tab) => can([...tab.any]) && (!('needs' in tab) || can([...tab.needs]))).map((tab) => (
        <NavLink key={tab.key} to={tab.to} end={'end' in tab} className={({ isActive }) => cn('rounded-input px-3 py-1.5 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light', isActive ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-100 hover:text-ink')}>
          {t(`inventory.tabs.${tab.key}`)}
        </NavLink>
      ))}
    </nav>
  );
}
