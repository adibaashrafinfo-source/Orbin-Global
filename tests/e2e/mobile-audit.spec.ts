import { test, expect, type Page } from '@playwright/test';
import { login } from './helpers';

// Phone width (390 x 844): every main screen must fit without sideways scrolling. Writes a short report to the test output.
const PAGES: Array<{ role: Parameters<typeof login>[1]; paths: string[] }> = [
  { role: 'super_admin', paths: ['/dashboard', '/products', '/products/new', '/settings/company', '/settings/users', '/settings/roles', '/settings/pos', '/settings/audit-log', '/admin/health', '/admin/integrity', '/settings/numbering', '/settings/period-lock', '/settings/branches', '/settings/master/vehicle-makes', '/settings/master/lookups', '/settings/master/custom-fields'] },
  { role: 'manager', paths: ['/reports', '/reports/sales-summary', '/reports/profit-loss', '/sales', '/sales/returns', '/customers', '/quotations', '/shifts', '/warranty', '/inventory', '/inventory/movements', '/inventory/reorder', '/inventory/valuation', '/inventory/adjustments', '/inventory/transfers', '/inventory/counts', '/purchases/suppliers', '/purchases/orders', '/purchases/receipts', '/purchases/returns', '/products/labels', '/products/import'] },
  { role: 'cashier', paths: ['/pos', '/sales', '/customers'] },
  { role: 'storekeeper', paths: ['/inventory', '/purchases/receipts', '/purchases/orders', '/products'] },
  { role: 'accountant', paths: ['/ledger/parties', '/ledger/accounts', '/ledger/receive', '/ledger/expenses', '/ledger/transfers', '/ledger/day-book', '/ledger/dues'] },
];

async function overflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

for (const group of PAGES) {
  test(`phone width: no sideways scroll for ${group.role}`, async ({ page }) => {
    test.setTimeout(240_000);
    await login(page, group.role);
    const failures: string[] = [];
    for (const path of group.paths) {
      await page.goto(path);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(400);
      const extra = await overflow(page);
      if (extra > 1) failures.push(`${path} overflows by ${extra}px`);
    }
    expect(failures, failures.join('\n')).toEqual([]);
  });
}
