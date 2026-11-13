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
const ProductsPage = lazy(() => import('@/features/catalog/list/ProductsPage').then((m) => ({ default: m.ProductsPage })));
const ProductEditorPage = lazy(() => import('@/features/catalog/editor/ProductEditorPage').then((m) => ({ default: m.ProductEditorPage })));
const LabelsPage = lazy(() => import('@/features/catalog/labels/LabelsPage').then((m) => ({ default: m.LabelsPage })));
const ImportPage = lazy(() => import('@/features/catalog/import/ImportPage').then((m) => ({ default: m.ImportPage })));
const OverviewPage = lazy(() => import('@/features/inventory/OverviewPage').then((m) => ({ default: m.OverviewPage })));
const MovementsPage = lazy(() => import('@/features/inventory/MovementsPage').then((m) => ({ default: m.MovementsPage })));
const StockCardPage = lazy(() => import('@/features/inventory/StockCardPage').then((m) => ({ default: m.StockCardPage })));
const BreakagePage = lazy(() => import('@/features/inventory/BreakagePage').then((m) => ({ default: m.BreakagePage })));
const AgingPage = lazy(() => import('@/features/inventory/AgingPage').then((m) => ({ default: m.AgingPage })));
const ValuationPage = lazy(() => import('@/features/inventory/ValuationPage').then((m) => ({ default: m.ValuationPage })));
const ReorderPage = lazy(() => import('@/features/inventory/ReorderPage').then((m) => ({ default: m.ReorderPage })));
const AdjustmentsPage = lazy(() => import('@/features/inventory/docs/AdjustmentsPage').then((m) => ({ default: m.AdjustmentsPage })));
const AdjustmentPage = lazy(() => import('@/features/inventory/docs/AdjustmentPage').then((m) => ({ default: m.AdjustmentPage })));
const TransfersPage = lazy(() => import('@/features/inventory/docs/TransfersPage').then((m) => ({ default: m.TransfersPage })));
const TransferPage = lazy(() => import('@/features/inventory/docs/TransferPage').then((m) => ({ default: m.TransferPage })));
const CountsPage = lazy(() => import('@/features/inventory/docs/CountsPage').then((m) => ({ default: m.CountsPage })));
const CountPage = lazy(() => import('@/features/inventory/docs/CountPage').then((m) => ({ default: m.CountPage })));
const SuppliersPage = lazy(() => import('@/features/purchasing/SuppliersPage').then((m) => ({ default: m.SuppliersPage })));
const OrdersPage = lazy(() => import('@/features/purchasing/OrdersPage').then((m) => ({ default: m.OrdersPage })));
const OrderPage = lazy(() => import('@/features/purchasing/OrderPage').then((m) => ({ default: m.OrderPage })));
const ReceiptsPage = lazy(() => import('@/features/purchasing/ReceiptsPage').then((m) => ({ default: m.ReceiptsPage })));
const ReceiptPage = lazy(() => import('@/features/purchasing/ReceiptPage').then((m) => ({ default: m.ReceiptPage })));
const ReturnsPage = lazy(() => import('@/features/purchasing/ReturnsPage').then((m) => ({ default: m.ReturnsPage })));
const ReturnPage = lazy(() => import('@/features/purchasing/ReturnPage').then((m) => ({ default: m.ReturnPage })));
const IntegrityPage = lazy(() => import('@/features/admin/IntegrityPage').then((m) => ({ default: m.IntegrityPage })));
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
              { path: '/products', element: gate('products.view', <ProductsPage />) },
              { path: '/products/new', element: gate('products.manage', <ProductEditorPage />) },
              { path: '/products/labels', element: gate('products.view', <LabelsPage />) },
              { path: '/products/import', element: gate(['products.import', 'masterdata.manage', 'inventory.adjust.approve'], <ImportPage />) },
              { path: '/products/:id', element: gate('products.view', <ProductEditorPage />) },
              { path: '/inventory', element: gate('inventory.view', <OverviewPage />) },
              { path: '/inventory/movements', element: gate('inventory.view', <MovementsPage />) },
              { path: '/inventory/card/:variantId', element: gate('inventory.view', <StockCardPage />) },
              { path: '/inventory/breakage', element: gate('inventory.view', <BreakagePage />) },
              { path: '/inventory/reorder', element: gate('inventory.view', <ReorderPage />) },
              { path: '/inventory/aging', element: gate('inventory.reports', <AgingPage />) },
              { path: '/inventory/valuation', element: gate('inventory.reports', gate('products.view_cost', <ValuationPage />)) },
              { path: '/inventory/adjustments', element: gate('inventory.view', <AdjustmentsPage />) },
              { path: '/inventory/adjustments/new', element: gate('inventory.adjust', <AdjustmentPage />) },
              { path: '/inventory/adjustments/:id', element: gate('inventory.view', <AdjustmentPage />) },
              { path: '/inventory/transfers', element: gate('inventory.view', <TransfersPage />) },
              { path: '/inventory/transfers/new', element: gate('inventory.transfer', <TransferPage />) },
              { path: '/inventory/transfers/:id', element: gate('inventory.view', <TransferPage />) },
              { path: '/inventory/counts', element: gate('inventory.view', <CountsPage />) },
              { path: '/inventory/counts/new', element: gate('inventory.count', <CountPage />) },
              { path: '/inventory/counts/:id', element: gate('inventory.view', <CountPage />) },
              { path: '/purchases', element: <Navigate to="/purchases/suppliers" replace /> },
              { path: '/purchases/suppliers', element: gate('purchases.view', <SuppliersPage />) },
              { path: '/purchases/orders', element: gate('purchases.view', <OrdersPage />) },
              { path: '/purchases/orders/new', element: gate('purchases.manage', <OrderPage />) },
              { path: '/purchases/orders/:id', element: gate('purchases.view', <OrderPage />) },
              { path: '/purchases/receipts', element: gate('purchases.view', <ReceiptsPage />) },
              { path: '/purchases/receipts/new', element: gate(['purchases.grn', 'purchases.manage'], <ReceiptPage />) },
              { path: '/purchases/receipts/:id', element: gate('purchases.view', <ReceiptPage />) },
              { path: '/purchases/returns', element: gate('purchases.view', <ReturnsPage />) },
              { path: '/purchases/returns/new', element: gate('purchases.manage', <ReturnPage />) },
              { path: '/purchases/returns/:id', element: gate('purchases.view', <ReturnPage />) },
              { path: '/admin/integrity', element: gate('settings.system.manage', <IntegrityPage />) },
              { path: '/admin/health', element: gate('settings.system.manage', <HealthPage />) },
              { path: '*', element: <Navigate to="/dashboard" replace /> },
            ],
          },
        ],
      },
    ],
  },
]);
