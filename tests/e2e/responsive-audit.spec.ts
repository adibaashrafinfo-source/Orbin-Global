import { test, expect, type Page } from '@playwright/test';
import { login } from './helpers';

// Every main screen must work on a phone, a small phone, a tablet and a small laptop: no sideways page scroll, and no text cut off
// by a box that hides overflow (text with an ellipsis is intended and is not counted).
const WIDTHS = [
  { name: 'small phone', width: 320, height: 640, mobile: true },
  { name: 'phone', width: 390, height: 844, mobile: true },
  { name: 'tablet', width: 768, height: 1024, mobile: true },
  { name: 'small laptop', width: 1024, height: 768, mobile: false },
] as const;

const PAGES: Array<{ role: Parameters<typeof login>[1]; paths: string[] }> = [
  { role: 'super_admin', paths: ['/dashboard', '/products', '/products/new', '/products/labels', '/settings/company', '/settings/branches', '/settings/users', '/settings/roles', '/settings/numbering', '/settings/pos', '/settings/period-lock', '/settings/audit-log', '/settings/master/vehicle-makes', '/settings/master/lookups', '/settings/master/custom-fields', '/admin/health', '/admin/integrity', '/profile', '/gallery'] },
  { role: 'manager', paths: ['/dashboard', '/reports', '/reports/sales-summary', '/reports/profit-loss', '/sales', '/sales/returns', '/customers', '/quotations', '/shifts', '/warranty', '/inventory', '/inventory/movements', '/inventory/reorder', '/inventory/valuation', '/inventory/adjustments', '/inventory/transfers', '/inventory/counts', '/purchases/suppliers', '/purchases/orders', '/purchases/receipts', '/purchases/returns', '/products/import'] },
  { role: 'cashier', paths: ['/pos', '/sales', '/customers'] },
  { role: 'storekeeper', paths: ['/inventory', '/purchases/receipts', '/purchases/orders', '/products'] },
  { role: 'accountant', paths: ['/ledger/parties', '/ledger/accounts', '/ledger/receive', '/ledger/expenses', '/ledger/transfers', '/ledger/day-book', '/ledger/dues'] },
];

/** Problems on the current page: sideways page scroll, and text clipped by a box that hides overflow. */
async function problems(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const out: string[] = [];
    const extra = document.documentElement.scrollWidth - document.documentElement.clientWidth;
    if (extra > 1) out.push(`page scrolls sideways by ${extra}px`);
    document.querySelectorAll<HTMLElement>('body *').forEach((el) => {
      if (el.closest('aside') || el.closest('[aria-hidden="true"]')) return;
      // Screen-reader-only text is clipped on purpose.
      if (el.classList.contains('sr-only') || el.getBoundingClientRect().width <= 1) return;
      const cs = getComputedStyle(el);
      if (cs.overflowX !== 'hidden' || cs.textOverflow === 'ellipsis') return;
      if (el.scrollWidth > el.clientWidth + 2 && el.clientWidth > 0 && (el.textContent ?? '').trim() !== '') {
        // Only text that really sticks out past the box counts (a decorative shine layer does not).
        const box = el.getBoundingClientRect();
        let reach = 0;
        el.querySelectorAll<HTMLElement>('*').forEach((d) => {
          if (Array.from(d.childNodes).some((n) => n.nodeType === 3 && (n.textContent ?? '').trim() !== '')) reach = Math.max(reach, d.getBoundingClientRect().right);
        });
        if (reach > box.right + 2) out.push(`clipped text in <${el.tagName.toLowerCase()}> "${(el.textContent ?? '').trim().slice(0, 40)}"`);
      }
    });
    return [...new Set(out)].slice(0, 6);
  });
}

test.use({ hasTouch: true });
for (const w of WIDTHS) {
  for (const group of PAGES) {
    test(`${w.name} ${w.width}px: ${group.role} screens fit`, async ({ browser }) => {
      test.setTimeout(420_000);
      const context = await browser.newContext({ viewport: { width: w.width, height: w.height }, isMobile: w.mobile, hasTouch: true });
      const page = await context.newPage();
      try {
        await login(page, group.role);
        const failures: string[] = [];
        for (const path of group.paths) {
          await page.goto(path);
          await page.waitForLoadState('networkidle');
          await page.waitForTimeout(300);
          for (const p of await problems(page)) failures.push(`${path}: ${p}`);
        }
        expect(failures, failures.join('\n')).toEqual([]);
      } finally {
        await context.close();
      }
    });
  }
}
