import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';
import { cn } from '@/lib/cn';

const TABS = ['suppliers', 'orders', 'receipts', 'returns'] as const;

/** PRD §8.7 tab strip shared by every purchasing screen. */
export function PurchasingTabs() {
  const { t } = useTranslation();
  return (
    <nav aria-label={t('nav.purchases')} className="mb-5 flex flex-wrap gap-1 border-b border-navy-100 pb-2">
      {TABS.map((tab) => (
        <NavLink key={tab} to={`/purchases/${tab}`} className={({ isActive }) => cn('rounded-input px-3 py-1.5 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light', isActive ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-100 hover:text-ink')}>
          {t(`purchasing.tabs.${tab}`)}
        </NavLink>
      ))}
    </nav>
  );
}
