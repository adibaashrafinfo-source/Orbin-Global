import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';
import { useSession } from '@/features/auth/useSession';
import { cn } from '@/lib/cn';
import { LEDGER_TABS } from './tabs';

/** PRD §8.9 tab strip shared by the finance screens; each tab shows only to people who may use it. */
export function LedgerTabs() {
  const { t } = useTranslation();
  const { can } = useSession();
  return (
    <nav aria-label={t('nav.ledger')} className="mb-5 flex flex-wrap gap-1 border-b border-navy-100 pb-2">
      {LEDGER_TABS.filter((tab) => can([...tab.any])).map((tab) => (
        <NavLink key={tab.key} to={tab.to} className={({ isActive }) => cn('rounded-input px-3 py-1.5 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light', isActive ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-100 hover:text-ink')}>{t(`ledger.tabs.${tab.key}`)}</NavLink>
      ))}
    </nav>
  );
}
