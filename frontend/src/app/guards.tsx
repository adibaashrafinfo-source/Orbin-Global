import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { ErrorState, PageSkeleton } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { ChangePasswordGate } from '@/features/auth/ChangePasswordGate';

/** Redirects guests to /login (remembering the target) and forces a password change when required. */
export function RequireAuth() {
  const { user, loading, error, refetch } = useSession();
  const location = useLocation();
  if (loading) {
    return (
      <div className="p-8">
        <PageSkeleton />
      </div>
    );
  }
  if (error) return <ErrorState onRetry={() => void refetch()} />;
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  if (user.must_change_password) return <ChangePasswordGate />;
  return <Outlet />;
}

/** Route-level permission gate (cosmetic; the API enforces the same keys). */
export function RequirePermission({ permission, children }: { permission: string | string[]; children?: ReactNode }) {
  const { t } = useTranslation();
  const { can } = useSession();
  if (!can(permission)) return <ErrorState title={t('errors.forbidden')} />;
  return children ? <>{children}</> : <Outlet />;
}
