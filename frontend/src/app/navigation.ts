import { BarChart3, BookOpen, Boxes, LayoutDashboard, Package, Receipt, Settings, ShoppingCart, Truck, Users, type LucideIcon } from 'lucide-react';

export interface NavItem {
  key: string;
  to: string;
  icon: LucideIcon;
  /** Any of these permissions grants visibility (super_admin always sees everything). */
  permissions: string[];
  /** Phase in which the screen ships; later phases are listed but disabled. */
  phase: 'P0' | 'P1' | 'P2' | 'P3' | 'P4' | 'P5';
}

export interface NavGroup {
  key: string;
  items: NavItem[];
}

/** Sidebar structure per spec routes; permission keys per roles.permissionKeys. */
export const NAV_GROUPS: NavGroup[] = [
  {
    key: 'sell',
    items: [
      { key: 'dashboard', to: '/dashboard', icon: LayoutDashboard, permissions: [], phase: 'P0' },
      { key: 'pos', to: '/pos', icon: ShoppingCart, permissions: ['pos.sell'], phase: 'P3' },
      { key: 'sales', to: '/sales', icon: Receipt, permissions: ['sales.view_all', 'sales.view_own'], phase: 'P3' },
      { key: 'customers', to: '/customers', icon: Users, permissions: ['customers.view'], phase: 'P3' },
    ],
  },
  {
    key: 'stock',
    items: [
      { key: 'products', to: '/products', icon: Package, permissions: ['products.view'], phase: 'P1' },
      { key: 'inventory', to: '/inventory', icon: Boxes, permissions: ['inventory.view'], phase: 'P2' },
      { key: 'purchases', to: '/purchases/suppliers', icon: Truck, permissions: ['purchases.view', 'purchases.manage', 'purchases.grn'], phase: 'P2' },
    ],
  },
  {
    key: 'money',
    items: [
      { key: 'ledger', to: '/ledger/parties', icon: BookOpen, permissions: ['ledger.view', 'ledger.receive_payment'], phase: 'P4' },
      { key: 'reports', to: '/reports', icon: BarChart3, permissions: ['reports.view_all', 'reports.view_own', 'reports.view_stock'], phase: 'P5' },
    ],
  },
  {
    key: 'system',
    items: [{ key: 'settings', to: '/settings/company', icon: Settings, permissions: ['settings.company.manage', 'settings.system.manage', 'users.manage', 'roles.manage', 'audit.view'], phase: 'P0' }],
  },
];

export const CURRENT_PHASE: NavItem['phase'] = 'P3';

const phaseNumber = (p: NavItem['phase']) => Number(p.slice(1));

/** A screen is live once its phase has shipped; later phases stay listed but disabled. */
export function isPhaseAvailable(phase: NavItem['phase']): boolean {
  return phaseNumber(phase) <= phaseNumber(CURRENT_PHASE);
}
