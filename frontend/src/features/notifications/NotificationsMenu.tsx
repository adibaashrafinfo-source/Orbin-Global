import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge, Button } from '@/design/components';
import { http, type Paginated } from '@/lib/api';
import { formatRelative } from '@/lib/format';

export interface NotificationItem {
  id: number;
  type: string;
  severity: 'info' | 'warning' | 'critical';
  title: string;
  body: string | null;
  is_read: boolean;
  created_at: string;
}

const KEY = ['notifications'] as const;

/** FR-109 bell: polled every 45 s (spec: polling replaces push). */
export function NotificationsMenu() {
  const { t, i18n } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({
    queryKey: KEY,
    queryFn: () => http.get<Paginated<NotificationItem> & { meta: { unread: number } }>('/notifications').then((r) => r.data),
    refetchInterval: 45_000,
  });
  const markAll = useMutation({ mutationFn: () => http.put('/notifications/read-all'), onSuccess: () => client.invalidateQueries({ queryKey: KEY }) });
  const markOne = useMutation({ mutationFn: (id: number) => http.put(`/notifications/${id}/read`), onSuccess: () => client.invalidateQueries({ queryKey: KEY }) });
  const unread = query.data?.meta.unread ?? 0;
  const items = query.data?.data ?? [];
  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger asChild>
        <button type="button" aria-label={unread > 0 ? `${t('nav.notifications')} (${t('notifications.unread', { count: unread })})` : t('nav.notifications')} className="relative flex h-10 w-10 items-center justify-center rounded-pill text-navy-700 hover:bg-navy-100 focus-visible:focus-ring-light">
          <Bell className="h-5 w-5" aria-hidden="true" />
          {unread > 0 && <span className="absolute right-1.5 top-1.5 flex h-4 min-w-4 items-center justify-center rounded-pill bg-orange-500 px-1 text-[10px] font-bold text-navy-900">{unread > 99 ? '99+' : unread}</span>}
        </button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Portal>
        <DropdownMenu.Content align="end" sideOffset={6} className="z-50 w-96 rounded-card border border-navy-200 bg-surface shadow-lg animate-in fade-in-0 zoom-in-95 motion-reduce:animate-none">
          <div className="flex items-center justify-between border-b border-navy-100 px-4 py-3">
            <span className="font-heading text-sm font-bold text-navy-900">{t('notifications.title')}</span>
            {unread > 0 && (
              <Button variant="ghost" size="sm" onClick={() => markAll.mutate()} loading={markAll.isPending}>
                {t('notifications.markAllRead')}
              </Button>
            )}
          </div>
          <ul className="max-h-96 overflow-y-auto">
            {items.length === 0 && <li className="px-4 py-8 text-center text-13 text-ink-muted">{t('notifications.empty')}</li>}
            {items.map((n) => (
              <li key={n.id} className="border-b border-navy-50 last:border-0">
                <DropdownMenu.Item onSelect={() => !n.is_read && markOne.mutate(n.id)} className="flex cursor-pointer flex-col gap-0.5 px-4 py-3 outline-none data-[highlighted]:bg-navy-50">
                  <div className="flex items-center gap-2">
                    {!n.is_read && <span className="h-2 w-2 rounded-pill bg-orange-500" aria-hidden="true" />}
                    <span className={n.is_read ? 'text-sm text-ink-muted' : 'text-sm font-semibold text-ink'}>{n.title}</span>
                    {n.severity !== 'info' && <Badge tone={n.severity === 'critical' ? 'danger' : 'warning'}>{n.severity}</Badge>}
                  </div>
                  {n.body && <span className="text-xs text-ink-muted">{n.body}</span>}
                  <span className="text-[11px] text-ink-subtle">{formatRelative(n.created_at, i18n.language)}</span>
                </DropdownMenu.Item>
              </li>
            ))}
          </ul>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  );
}
