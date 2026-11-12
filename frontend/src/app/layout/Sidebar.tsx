import { motion, useReducedMotion } from 'framer-motion';
import { PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';
import { LogoEmblem, LogoPlate, Tooltip } from '@/design/components';
import { cn } from '@/lib/cn';
import { useSession } from '@/features/auth/useSession';
import { CURRENT_PHASE, NAV_GROUPS } from '../navigation';

interface SidebarProps {
  collapsed: boolean;
  onToggle: () => void;
  logoUrl?: string | null;
}

/** Navy sidebar 264 / 72 px; active item = 3px orange bar + navy-700 bg + white text; tooltips when collapsed. */
export function Sidebar({ collapsed, onToggle, logoUrl }: SidebarProps) {
  const { t } = useTranslation();
  const { can } = useSession();
  const reduced = useReducedMotion();
  return (
    <motion.aside
      aria-label={t('nav.settings')}
      initial={false}
      animate={{ width: collapsed ? 72 : 264 }}
      transition={reduced ? { duration: 0 } : { duration: 0.22, ease: [0.2, 0, 0, 1] }}
      className="on-dark fixed inset-y-0 left-0 z-30 flex flex-col overflow-hidden bg-navy-900 text-surface"
    >
      <div className={cn('flex shrink-0 items-center justify-center border-b border-navy-800', collapsed ? 'h-topbar' : 'px-4 py-4')}>
        {collapsed ? <LogoEmblem src={logoUrl} size={44} /> : <LogoPlate src={logoUrl} width={180} className="rounded-input p-1.5 shadow-none" />}
      </div>
      <nav className="flex-1 overflow-y-auto px-3 py-4">
        {NAV_GROUPS.map((group) => {
          const items = group.items.filter((i) => i.permissions.length === 0 || can(i.permissions));
          if (items.length === 0) return null;
          return (
            <div key={group.key} className="mb-5">
              {!collapsed && <p className="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-navy-300">{t(`nav.groups.${group.key}`)}</p>}
              <ul className="flex flex-col gap-0.5">
                {items.map((item) => {
                  const Icon = item.icon;
                  const available = item.phase === CURRENT_PHASE;
                  const label = t(`nav.${item.key}`);
                  const body = (
                    <>
                      <Icon className="h-5 w-5 shrink-0" aria-hidden="true" />
                      {!collapsed && <span className="truncate">{label}</span>}
                      {!collapsed && !available && <span className="ml-auto rounded-pill bg-navy-800 px-1.5 py-0.5 text-[10px] font-semibold text-navy-300">{item.phase}</span>}
                    </>
                  );
                  const classes = (active: boolean) =>
                    cn(
                      'relative flex h-10 items-center gap-3 rounded-input px-3 text-sm font-medium transition-colors duration-fast focus-visible:focus-ring-dark',
                      collapsed && 'justify-center px-0',
                      active ? 'bg-navy-700 text-surface before:absolute before:inset-y-2 before:left-0 before:w-[3px] before:rounded-r before:bg-orange-500' : 'text-navy-200 hover:bg-navy-800 hover:text-surface',
                      !available && 'cursor-not-allowed opacity-50',
                    );
                  const link = available ? (
                    <NavLink to={item.to} className={({ isActive }) => classes(isActive)} aria-label={collapsed ? label : undefined}>
                      {body}
                    </NavLink>
                  ) : (
                    <span className={classes(false)} aria-disabled="true" aria-label={collapsed ? `${label} (${t('common.comingSoon')})` : undefined} title={t('common.comingSoon')}>
                      {body}
                    </span>
                  );
                  return <li key={item.key}>{collapsed ? <Tooltip content={label} side="right">{link}</Tooltip> : link}</li>;
                })}
              </ul>
            </div>
          );
        })}
      </nav>
      <div className="border-t border-navy-800 p-3">
        <button
          type="button"
          onClick={onToggle}
          aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
          aria-expanded={!collapsed}
          className={cn('flex h-10 w-full items-center gap-3 rounded-input px-3 text-sm text-navy-200 hover:bg-navy-800 hover:text-surface focus-visible:focus-ring-dark', collapsed && 'justify-center px-0')}
        >
          {collapsed ? <PanelLeftOpen className="h-5 w-5" aria-hidden="true" /> : <PanelLeftClose className="h-5 w-5" aria-hidden="true" />}
          {!collapsed && <span>{t('nav.collapse')}</span>}
        </button>
      </div>
    </motion.aside>
  );
}
