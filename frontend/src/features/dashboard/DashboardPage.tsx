import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Building2, ShieldCheck, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Badge, Card, CardBody, PageHeader } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { companyApi, COMPANY_KEY } from '@/features/settings/company/api';

/** Phase 0 landing: greeting plus the foundation screens the user may open. Widgets arrive in P5. */
export function DashboardPage() {
  const { t } = useTranslation();
  const { user, can } = useSession();
  const company = useQuery({ queryKey: COMPANY_KEY, queryFn: companyApi.show });
  const links = [
    { to: '/settings/company', icon: Building2, label: t('settings.company'), allowed: can('settings.company.manage'), badge: company.data ? (company.data.invoice_ready ? t('company.previewReady') : t('company.previewBlocked')) : null, ok: company.data?.invoice_ready ?? false },
    { to: '/settings/users', icon: Users, label: t('settings.users'), allowed: can('users.manage'), badge: null, ok: true },
    { to: '/admin/health', icon: ShieldCheck, label: t('health.title'), allowed: can('settings.system.manage'), badge: null, ok: true },
  ].filter((l) => l.allowed);

  return (
    <>
      <PageHeader title={t('dashboard.greeting', { name: user?.full_name ?? '' })} description={t('dashboard.subtitle')} />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {links.map((l) => {
          const Icon = l.icon;
          return (
            <Link key={l.to} to={l.to} className="group rounded-card focus-visible:focus-ring-light">
              <Card interactive className="mirror-shine h-full">
                <CardBody className="flex flex-col gap-3">
                  <div className="flex items-center justify-between">
                    <span className="flex h-10 w-10 items-center justify-center rounded-input bg-navy-50 text-navy-700">
                      <Icon className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <ArrowRight className="h-4 w-4 text-ink-subtle transition-transform duration-base group-hover:translate-x-1 motion-reduce:transform-none" aria-hidden="true" />
                  </div>
                  <span className="font-heading text-base font-bold text-navy-900">{l.label}</span>
                  {l.badge && <Badge tone={l.ok ? 'success' : 'orange'}>{l.badge}</Badge>}
                </CardBody>
              </Card>
            </Link>
          );
        })}
      </div>
    </>
  );
}
