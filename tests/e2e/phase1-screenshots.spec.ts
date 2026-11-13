import { test } from '@playwright/test';
import { login } from './helpers';

// Not part of the regression run: `npx playwright test phase1-screenshots.spec.ts` refreshes docs/screenshots/p1.
const SIZES = [{ w: 1366, h: 768 }, { w: 1920, h: 1080 }];
const SHOTS: [string, string][] = [
  ['products-grid', '/products'],
  ['editor-basic', '/products/1'],
  ['editor-variants', '/products/1?step=variants'],
  ['editor-images', '/products/1?step=images'],
  ['editor-fitment', '/products/1?step=fitment'],
  ['editor-pricing', '/products/1?step=pricing'],
  ['editor-stock', '/products/1?step=stock'],
  ['editor-review', '/products/1?step=review'],
  ['labels', '/products/labels'],
  ['import', '/products/import'],
  ['master-categories', '/settings/master/categories'],
  ['master-lookups', '/settings/master/lookups'],
  ['master-custom-fields', '/settings/master/custom-fields'],
];

test.skip(!process.env.SHOTS, 'set SHOTS=1 to capture');
for (const { w, h } of SIZES) {
  test(`screenshots ${w}x${h}`, async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: w, height: h });
    await login(page, 'super_admin');
    for (const [name, path] of SHOTS) {
      await page.goto(path);
      await page.waitForTimeout(900);
      await page.screenshot({ path: `../../docs/screenshots/p1/${name}-${w}.png` });
    }
  });
}

test('screenshots bangla 1366x768', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1366, height: 768 });
  await login(page, 'super_admin');
  await page.getByRole('button', { name: 'বাং' }).click();
  for (const [name, path] of [['products-grid', '/products'], ['editor-pricing', '/products/1?step=pricing'], ['editor-review', '/products/1?step=review'], ['labels', '/products/labels']] as const) {
    await page.goto(path);
    await page.waitForTimeout(900);
    await page.screenshot({ path: `../../docs/screenshots/p1/${name}-bn-1366.png` });
  }
});
