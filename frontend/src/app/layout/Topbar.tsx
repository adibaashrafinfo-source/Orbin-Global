import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { ChevronDown, LogOut, Menu, Search, UserRound } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Badge } from '@/design/components';
import { useLogout, useSession } from '@/features/auth/useSession';
import { NotificationsMenu } from '@/features/notifications/NotificationsMenu';
import { LanguageToggle } from './LanguageToggle';
import { ShiftPill } from './ShiftPill';

/** White 64px top bar: global search (/ focuses), branch selector, shift pill, bell, language, user menu. */
export function Topbar({ branchName, onMenu, menuOpen }: { branchName?: string | undefined; onMenu: () => void; menuOpen: boolean }) {
  const { t } = useTranslation();
  const { user } = useSession();
  const logout = useLogout();
  const searchRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement | null;
      const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
      if (e.key === '/' && !typing) {
        e.preventDefault();
        searchRef.current?.focus();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  return (
    <header className="sticky top-0 z-20 flex h-topbar items-center gap-2 border-b border-navy-200 bg-surface px-3 sm:gap-4 sm:px-4 lg:px-6">
      <button type="button" onClick={onMenu} aria-label={t(menuOpen ? 'nav.closeMenu' : 'nav.openMenu')} aria-expanded={menuOpen} className="flex h-10 w-10 shrink-0 items-center justify-center rounded-input text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light lg:hidden">
        <Menu className="h-5 w-5" aria-hidden="true" />
      </button>
      <label className="relative min-w-0 flex-1 max-w-md">
        <span className="sr-only">{t('common.search')}</span>
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
        <input ref={searchRef} type="search" placeholder={t('nav.globalSearch')} className="h-10 w-full min-w-0 rounded-input border border-navy-200 bg-navy-50 pl-9 pr-3 text-sm focus:border-navy-600 focus:bg-surface focus:outline-none focus:shadow-[0_0_0_3px_theme(colors.navy.600/0.15)]" />
      </label>
      <div className="ml-auto flex items-center gap-1.5 sm:gap-3">
        {branchName && <span className="hidden md:inline-flex"><Badge tone="navy">{branchName}</Badge></span>}
        <ShiftPill />
        <NotificationsMenu />
        <LanguageToggle />
        <DropdownMenu.Root>
          <DropdownMenu.Trigger asChild>
            <button type="button" aria-label={user?.full_name ?? t('nav.userMenu')} className="flex h-10 items-center gap-2 rounded-pill border border-navy-200 pl-1 pr-3 text-sm hover:bg-navy-50 focus-visible:focus-ring-light">
              <span className="flex h-8 w-8 items-center justify-center rounded-pill bg-navy-100 text-navy-700">
                {user?.avatar_url ? <img src={user.avatar_url} alt="" className="h-8 w-8 rounded-pill object-cover" /> : <UserRound className="h-4 w-4" aria-hidden="true" />}
              </span>
              <span className="hidden max-w-[140px] truncate font-medium sm:block">{user?.full_name}</span>
              <ChevronDown className="hidden h-4 w-4 text-ink-muted sm:block" aria-hidden="true" />
            </button>
          </DropdownMenu.Trigger>
          <DropdownMenu.Portal>
            <DropdownMenu.Content align="end" sideOffset={6} className="z-50 min-w-[220px] rounded-card border border-navy-200 bg-surface p-1 shadow-lg animate-in fade-in-0 zoom-in-95 motion-reduce:animate-none">
              <div className="px-3 py-2">
                <p className="text-sm font-semibold text-ink">{user?.full_name}</p>
                <p className="text-xs text-ink-muted">{user ? t(`users.roles.${user.role}`) : ''}</p>
              </div>
              <DropdownMenu.Separator className="my-1 h-px bg-navy-100" />
              <DropdownMenu.Item asChild>
                <Link to="/profile" className="flex h-9 cursor-pointer items-center gap-2 rounded-input px-3 text-sm outline-none data-[highlighted]:bg-navy-100">
                  <UserRound className="h-4 w-4" aria-hidden="true" />
                  {t('auth.profile')}
                </Link>
              </DropdownMenu.Item>
              <DropdownMenu.Item onSelect={() => logout.mutate()} className="flex h-9 cursor-pointer items-center gap-2 rounded-input px-3 text-sm text-danger outline-none data-[highlighted]:bg-danger/10">
                <LogOut className="h-4 w-4" aria-hidden="true" />
                {t('auth.signOut')}
              </DropdownMenu.Item>
            </DropdownMenu.Content>
          </DropdownMenu.Portal>
        </DropdownMenu.Root>
      </div>
    </header>
  );
}
