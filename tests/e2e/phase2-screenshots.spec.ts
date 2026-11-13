import { test } from '@playwright/test';
import { login } from './helpers';

// Not part of the regression run: `SHOTS=1 npx playwright test phase2-screenshots.spec.ts` refreshes docs/screenshots/p2.
// Run it right after phase2-stock.spec.ts so the screens show the books that spec created.
const SIZES = [{ w: 1366, h: 768 }, { w: 1920, h: 1080 }];
const SHOTS: [string, string][] = [
  ['inventory-overview', '/inventory'],
  ['inventory-movements', '/inventory/movements'],
  ['inventory-breakage', '/inventory/breakage'],
  ['inventory-reorder', '/inventory/reorder'],
  ['inventory-aging', '/inventory/aging'],
  ['inventory-valuation', '/inventory/valuation'],
  ['adjustments', '/inventory/adjustments'],
  ['adjustment-new', '/inventory/adjustments/new'],
  ['transfers', '/inventory/transfers'],
  ['transfer-new', '/inventory/transfers/new'],
  ['counts', '/inventory/counts'],
  ['suppliers', '/purchases/suppliers'],
  ['orders', '/purchases/orders'],
  ['order-new', '/purchases/orders/new'],
  ['receipts', '/purchases/receipts'],
  ['receipt-detail', '/purchases/receipts/1'],
  ['receipt-new', '/purchases/receipts/new'],
  ['purchase-returns', '/purchases/returns'],
  ['integrity', '/admin/integrity'],
];

test.skip(!process.env.SHOTS, 'set SHOTS=1 to capture');
for (const { w, h } of SIZES) {
  test(`screenshots ${w}x${h}`, async ({ page }) => {
    test.setTimeout(180_000);
    await page.setViewportSize({ width: w, height: h });
    await login(page, 'super_admin');
    for (const [name, path] of SHOTS) {
      await page.goto(path);
      await page.waitForTimeout(900);
      await page.screenshot({ path: `../../docs/screenshots/p2/${name}-${w}.png` });
    }
  });
}

test('screenshots bangla 1366x768', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1366, height: 768 });
  await login(page, 'super_admin');
  await page.getByRole('button', { name: 'বাং' }).click();
  for (const [name, path] of [['inventory-overview', '/inventory'], ['receipt-detail', '/purchases/receipts/1'], ['suppliers', '/purchases/suppliers'], ['integrity', '/admin/integrity']] as const) {
    await page.goto(path);
    await page.waitForTimeout(900);
    await page.screenshot({ path: `../../docs/screenshots/p2/${name}-bn-1366.png` });
  }
});
