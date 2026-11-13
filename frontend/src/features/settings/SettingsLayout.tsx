import { useTranslation } from 'react-i18next';
import { NavLink, Outlet } from 'react-router-dom';
import { cn } from '@/lib/cn';
import { useSession } from '@/features/auth/useSession';

interface SubItem {
  key: string;
  to: string;
  permissions: string[];
  available: boolean;
}

/** PRD §8.11 settings left sub-nav. Items from later phases are listed but disabled. */
const ITEMS: SubItem[] = [
  { key: 'company', to: '/settings/company', permissions: ['settings.company.manage'], available: true },
  { key: 'branches', to: '/settings/branches', permissions: ['settings.system.manage'], available: true },
  { key: 'users', to: '/settings/users', permissions: ['users.manage'], available: true },
  { key: 'roles', to: '/settings/roles', permissions: ['roles.manage'], available: true },
  { key: 'masterData', to: '/settings/master/vehicle-makes', permissions: ['masterdata.view', 'masterdata.manage'], available: true },
  { key: 'paymentAccounts', to: '/ledger/accounts', permissions: ['accounts.manage'], available: true },
  { key: 'numbering', to: '/settings/numbering', permissions: ['settings.system.manage'], available: true },
  { key: 'pos', to: '/settings/pos', permissions: ['settings.system.manage'], available: true },
  { key: 'periodLock', to: '/settings/period-lock', permissions: ['periods.lock'], available: true },
  { key: 'invoice', to: '/settings/invoice', permissions: ['settings.system.manage'], available: false },
  { key: 'notifications', to: '/settings/notifications', permissions: ['settings.system.manage'], available: false },
  { key: 'importExport', to: '/settings/import-export', permissions: ['products.import'], available: false },
  { key: 'auditLog', to: '/settings/audit-log', permissions: ['audit.view'], available: true },
  { key: 'integrity', to: '/admin/integrity', permissions: ['settings.system.manage'], available: true },
  { key: 'system', to: '/admin/health', permissions: ['settings.system.manage'], available: true },
];

export function SettingsLayout() {
  const { t } = useTranslation();
  const { can } = useSession();
  const items = ITEMS.filter((i) => can(i.permissions));
  return (
    <div className="flex gap-6">
      <nav aria-label={t('settings.title')} className="w-56 shrink-0">
        <p className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">{t('settings.title')}</p>
        <ul className="flex flex-col gap-0.5">
          {items.map((item) =>
            item.available ? (
              <li key={item.key}>
                <NavLink to={item.to} className={({ isActive }) => cn('flex h-9 items-center rounded-input px-3 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-light', isActive ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-100 hover:text-ink')}>
                  {t(`settings.${item.key}`)}
                </NavLink>
              </li>
            ) : (
              <li key={item.key}>
                <span className="flex h-9 cursor-not-allowed items-center rounded-input px-3 text-sm text-ink-muted opacity-60" aria-disabled="true" title={t('common.comingSoon')}>
                  {t(`settings.${item.key}`)}
                </span>
              </li>
            ),
          )}
        </ul>
      </nav>
      <div className="min-w-0 flex-1">
        <Outlet />
      </div>
    </div>
  );
}
