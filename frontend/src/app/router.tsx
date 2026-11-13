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
const PosPage = lazy(() => import('@/features/sales/pos/PosPage').then((m) => ({ default: m.PosPage })));
const CustomersPage = lazy(() => import('@/features/sales/customers/CustomersPage').then((m) => ({ default: m.CustomersPage })));
const CustomerPage = lazy(() => import('@/features/sales/customers/CustomerPage').then((m) => ({ default: m.CustomerPage })));
const SalesPage = lazy(() => import('@/features/sales/sales/SalesPage').then((m) => ({ default: m.SalesPage })));
const SaleDetailPage = lazy(() => import('@/features/sales/sales/SaleDetailPage').then((m) => ({ default: m.SaleDetailPage })));
const SaleReturnsPage = lazy(() => import('@/features/sales/sales/SaleReturnsPage').then((m) => ({ default: m.SaleReturnsPage })));
const SaleReturnPage = lazy(() => import('@/features/sales/sales/SaleReturnPage').then((m) => ({ default: m.SaleReturnPage })));
const ShiftsPage = lazy(() => import('@/features/sales/shifts/ShiftsPage').then((m) => ({ default: m.ShiftsPage })));
const ShiftPage = lazy(() => import('@/features/sales/shifts/ShiftPage').then((m) => ({ default: m.ShiftPage })));
const QuotationsPage = lazy(() => import('@/features/sales/quotations/QuotationsPage').then((m) => ({ default: m.QuotationsPage })));
const QuotationPage = lazy(() => import('@/features/sales/quotations/QuotationPage').then((m) => ({ default: m.QuotationPage })));
const WarrantyPage = lazy(() => import('@/features/sales/warranty/WarrantyPage').then((m) => ({ default: m.WarrantyPage })));
const LedgerIndex = lazy(() => import('@/features/ledger/LedgerIndex').then((m) => ({ default: m.LedgerIndex })));
const PartyLedgerPage = lazy(() => import('@/features/ledger/PartyLedgerPage').then((m) => ({ default: m.PartyLedgerPage })));
const FinanceAccountsPage = lazy(() => import('@/features/ledger/AccountsPage').then((m) => ({ default: m.AccountsPage })));
const ReceivePayPage = lazy(() => import('@/features/ledger/ReceivePayPage').then((m) => ({ default: m.ReceivePayPage })));
const FinanceExpensesPage = lazy(() => import('@/features/ledger/ExpensesPage').then((m) => ({ default: m.ExpensesPage })));
const FundTransfersPage = lazy(() => import('@/features/ledger/TransfersPage').then((m) => ({ default: m.TransfersPage })));
const DayBookPage = lazy(() => import('@/features/ledger/DayBookPage').then((m) => ({ default: m.DayBookPage })));
const DuesPage = lazy(() => import('@/features/ledger/DuesPage').then((m) => ({ default: m.DuesPage })));
const PeriodLockPage = lazy(() => import('@/features/settings/period-lock/PeriodLockPage').then((m) => ({ default: m.PeriodLockPage })));
const ReportsHubPage = lazy(() => import('@/features/reports/ReportsHubPage').then((m) => ({ default: m.ReportsHubPage })));
const ReportPage = lazy(() => import('@/features/reports/ReportPage').then((m) => ({ default: m.ReportPage })));
const IntegrityPage = lazy(() => import('@/features/admin/IntegrityPage').then((m) => ({ default: m.IntegrityPage })));
const HealthPage = lazy(() => import('@/features/admin/HealthPage').then((m) => ({ default: m.HealthPage })));

const Lazy = () => (
  <Suspense fallback={<PageSkeleton />}>
    <Outlet />
  </Suspense>
);

const REPORT_PERMS = ['reports.view_all', 'reports.view_own', 'reports.view_stock', 'reports.view_profit', 'audit.view'];

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
                  { path: 'period-lock', element: gate('periods.lock', <PeriodLockPage />) },
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
              { path: '/pos', element: gate('pos.sell', <PosPage />) },
              { path: '/customers', element: gate('customers.view', <CustomersPage />) },
              { path: '/customers/:id', element: gate('customers.view', <CustomerPage />) },
              { path: '/sales', element: gate(['sales.view_all', 'sales.view_own'], <SalesPage />) },
              { path: '/sales/returns', element: gate(['sales.view_all', 'sales.view_own'], <SaleReturnsPage />) },
              { path: '/sales/returns/:id', element: gate(['sales.view_all', 'sales.view_own'], <SaleReturnPage />) },
              { path: '/sales/:id', element: gate(['sales.view_all', 'sales.view_own'], <SaleDetailPage />) },
              { path: '/quotations', element: gate('quotations.manage', <QuotationsPage />) },
              { path: '/quotations/:id', element: gate('quotations.manage', <QuotationPage />) },
              { path: '/shifts', element: gate(['shifts.open_close_own', 'shifts.close_any', 'shifts.view_all'], <ShiftsPage />) },
              { path: '/shifts/:id', element: gate(['shifts.open_close_own', 'shifts.close_any', 'shifts.view_all'], <ShiftPage />) },
              { path: '/warranty', element: gate('sales.view_all', <WarrantyPage />) },
              { path: '/ledger', element: <LedgerIndex /> },
              { path: '/ledger/parties', element: gate('ledger.view', <PartyLedgerPage />) },
              { path: '/ledger/accounts', element: gate(['ledger.view', 'accounts.manage'], <FinanceAccountsPage />) },
              { path: '/ledger/receive', element: gate(['ledger.receive_payment', 'ledger.pay_supplier'], <ReceivePayPage />) },
              { path: '/ledger/expenses', element: gate('expenses.manage', <FinanceExpensesPage />) },
              { path: '/ledger/transfers', element: gate('fund_transfers.manage', <FundTransfersPage />) },
              { path: '/ledger/day-book', element: gate('ledger.view', <DayBookPage />) },
              { path: '/ledger/dues', element: gate('ledger.view', <DuesPage />) },
              { path: '/reports', element: gate(REPORT_PERMS, <ReportsHubPage />) },
              { path: '/reports/:id', element: gate(REPORT_PERMS, <ReportPage />) },
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
