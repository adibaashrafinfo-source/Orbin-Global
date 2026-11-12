import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';
import { cn } from '@/lib/cn';
import { RESOURCE_ORDER } from './config';

const EXTRA = [
  { key: 'lookups', to: '/settings/master/lookups' },
  { key: 'customFields', to: '/settings/master/custom-fields' },
] as const;

/** Secondary tab strip shared by every master-data screen. */
export function MasterDataTabs() {
  const { t } = useTranslation();
  const items = [...RESOURCE_ORDER.map((r) => ({ key: r, to: `/settings/master/${r}`, label: t(`masterData.plural.${r}`) })), ...EXTRA.map((e) => ({ ...e, label: t(`masterData.${e.key}`) }))];
  return (
    <nav aria-label={t('settings.masterData')} className="mb-5 flex flex-wrap gap-1 border-b border-navy-100 pb-2">
      {items.map((i) => (
        <NavLink
          key={i.key}
          to={i.to}
          className={({ isActive }) => cn('rounded-input px-3 py-1.5 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light', isActive ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-100 hover:text-ink')}
        >
          {i.label}
        </NavLink>
      ))}
    </nav>
  );
}
