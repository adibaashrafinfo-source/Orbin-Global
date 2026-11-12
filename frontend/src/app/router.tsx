import { lazy, Suspense } from 'react';
import { createBrowserRouter, Navigate, Outlet } from 'react-router-dom';
import { PageSkeleton } from '@/design/components';
import { LoginPage } from '@/features/auth/LoginPage';
import { AppShell } from './layout/AppShell';
import { RequireAuth, RequirePermission } from './guards';

// Route chunks are lazy-loaded (NFR-001 first load).
const DashboardPage = lazy(() => import('@/features/dashboard/DashboardPage').then((m) => ({ default: m.DashboardPage })));
const GalleryPage = lazy(() => import('@/features/gallery/GalleryPage').then((m) => ({ default: m.GalleryPage })));
const ProfilePage = lazy(() => import('@/features/profile/ProfilePage').then((m) => ({ default: m.ProfilePage })));
const SettingsLayout = lazy(() => import('@/features/settings/SettingsLayout').then((m) => ({ default: m.SettingsLayout })));
const CompanyProfilePage = lazy(() => import('@/features/settings/company/CompanyProfilePage').then((m) => ({ default: m.CompanyProfilePage })));
const BranchesPage = lazy(() => import('@/features/settings/branches/BranchesPage').then((m) => ({ default: m.BranchesPage })));
const UsersPage = lazy(() => import('@/features/settings/users/UsersPage').then((m) => ({ default: m.UsersPage })));
const RolesPage = lazy(() => import('@/features/settings/roles/RolesPage').then((m) => ({ default: m.RolesPage })));
const NumberingPage = lazy(() => import('@/features/settings/numbering/NumberingPage').then((m) => ({ default: m.NumberingPage })));
const PosRulesPage = lazy(() => import('@/features/settings/app-settings/PosRulesPage').then((m) => ({ default: m.PosRulesPage })));
const AuditLogPage = lazy(() => import('@/features/settings/audit/AuditLogPage').then((m) => ({ default: m.AuditLogPage })));
const MasterDataPage = lazy(() => import('@/features/masterdata/MasterDataPage').then((m) => ({ default: m.MasterDataPage })));
const LookupsPage = lazy(() => import('@/features/masterdata/LookupsPage').then((m) => ({ default: m.LookupsPage })));
const CustomFieldsPage = lazy(() => import('@/features/masterdata/CustomFieldsPage').then((m) => ({ default: m.CustomFieldsPage })));
const HealthPage = lazy(() => import('@/features/admin/HealthPage').then((m) => ({ default: m.HealthPage })));

const Lazy = () => (
  <Suspense fallback={<PageSkeleton />}>
    <Outlet />
  </Suspense>
);

const gate = (permission: string | string[], element: React.ReactNode) => <RequirePermission permission={permission}>{element}</RequirePermission>;

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppShell />,
        children: [
          {
            element: <Lazy />,
            children: [
              { index: true, element: <Navigate to="/dashboard" replace /> },
              { path: '/dashboard', element: <DashboardPage /> },
              { path: '/profile', element: <ProfilePage /> },
              { path: '/gallery', element: <GalleryPage /> },
              {
                path: '/settings',
                element: <SettingsLayout />,
                children: [
                  { index: true, element: <Navigate to="/settings/company" replace /> },
                  { path: 'company', element: gate('settings.company.manage', <CompanyProfilePage />) },
                  { path: 'branches', element: gate('settings.system.manage', <BranchesPage />) },
                  { path: 'users', element: gate('users.manage', <UsersPage />) },
                  { path: 'roles', element: gate('roles.manage', <RolesPage />) },
                  { path: 'numbering', element: gate('settings.system.manage', <NumberingPage />) },
                  { path: 'pos', element: gate('settings.system.manage', <PosRulesPage />) },
                  { path: 'master', element: <Navigate to="/settings/master/vehicle-makes" replace /> },
                  { path: 'master/lookups', element: gate(['masterdata.view', 'masterdata.manage'], <LookupsPage />) },
                  { path: 'master/custom-fields', element: gate(['masterdata.view', 'masterdata.manage'], <CustomFieldsPage />) },
                  { path: 'master/:resource', element: gate(['masterdata.view', 'masterdata.manage'], <MasterDataPage />) },
                  { path: 'audit-log', element: gate('audit.view', <AuditLogPage />) },
                ],
              },
              { path: '/admin/health', element: gate('settings.system.manage', <HealthPage />) },
              { path: '*', element: <Navigate to="/dashboard" replace /> },
            ],
          },
        ],
      },
    ],
  },
]);
