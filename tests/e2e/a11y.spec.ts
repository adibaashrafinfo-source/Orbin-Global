import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';

// WCAG 2.2 AA audit (P6, AT-020). Fails on any serious or critical violation on the screens a person uses daily.
// Lighthouse covers colour and performance (docs/phase-6-summary.md); this runs in the browser on every regression pass.
const CHECKS = [
  { name: 'login', path: '/login', as: null },
  { name: 'pos', path: '/pos', as: 'cashier' },
  { name: 'dashboard', path: '/dashboard', as: 'manager' },
  { name: 'sales list', path: '/sales', as: 'manager' },
  { name: 'reports hub', path: '/reports', as: 'manager' },
  { name: 'report page', path: '/reports/sales-summary?range=month', as: 'manager' },
] as const;

async function audit(page: Page) {
  return new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
}

test.describe.configure({ mode: 'serial' });

for (const check of CHECKS) {
  test(`axe: no serious or critical WCAG AA violations on ${check.name}`, async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: 1366, height: 768 });
    if (check.as) {
      await login(page, check.as);
    } else {
      await page.goto('/login');
    }
    await page.goto(check.path);
    await page.waitForLoadState('networkidle');
    const results = await audit(page);
    const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const summary = blocking.map((v) => `${v.id} (${v.impact}): ${v.nodes.length} node(s), e.g. ${v.nodes[0]?.target.join(' ')}`);
    expect(summary, summary.join('\n')).toEqual([]);
  });
}
