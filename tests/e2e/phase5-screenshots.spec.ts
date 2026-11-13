import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';

// Not part of the regression run: `SHOTS=1 npx playwright test phase5-screenshots.spec.ts` refreshes docs/screenshots/p5.
// It adds its own sales, so it can run on any seeded database.
test.skip(!process.env.SHOTS, 'set SHOTS=1 to capture');

const HERE = path.dirname(fileURLToPath(import.meta.url));
const BACKEND = path.resolve(HERE, '../../backend');
const OUT = path.resolve(HERE, '../../docs/screenshots/p5');
const SIZES = [{ w: 1366, h: 768 }, { w: 1920, h: 1080 }];
const settle = (page: Page) => page.waitForTimeout(1600); // the dashboard counts up for about a second

function seed(): void {
  // The foundation spec uploads a test logo to the company profile; clear it so the screenshots show the real brand logo.
  execFileSync('php', ['artisan', 'tinker', "--execute=App\\Models\\CompanyProfile::current()->forceFill(['logo_url' => null, 'logo_storage_path' => null])->save();"], { cwd: BACKEND });
  execFileSync('php', ['artisan', 'tinker', `--execute=require '${path.resolve(HERE, 'fixtures/reports-seed.php')}';`], { cwd: BACKEND });
}

const REPORTS = [['hub', '/reports'], ['sales-summary', '/reports/sales-summary?range=month'], ['profit-by-product', '/reports/profit-by-product?range=month'], ['stock-valuation', '/reports/stock-valuation'], ['profit-loss', '/reports/profit-loss?range=month'], ['receivable-aging', '/reports/receivable-aging']] as const;

for (const { w, h } of SIZES) {
  test(`phase 5 screenshots ${w}x${h}`, async ({ page }) => {
    test.setTimeout(150_000);
    seed();
    await page.setViewportSize({ width: w, height: h });
    await login(page, 'manager');
    await page.goto('/dashboard');
    await expect(page.getByTestId('kpi-sales')).toBeVisible();
    await settle(page);
    await page.screenshot({ path: `${OUT}/dashboard-${w}.png` });
    await page.screenshot({ path: `${OUT}/dashboard-full-${w}.png`, fullPage: true });

    for (const [name, url] of REPORTS) {
      await page.goto(url);
      await settle(page);
      await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
    }

    await page.goto('/reports/sales-summary?range=month');
    await page.getByRole('switch', { name: 'Compare to previous period' }).click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/compare-${w}.png` });
    await page.getByRole('button', { name: /Export/ }).click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/export-menu-${w}.png` });
    await page.keyboard.press('Escape');
  });
}

test('phase 5 screenshots bangla 1366x768', async ({ page }) => {
  test.setTimeout(120_000);
  seed();
  await page.setViewportSize({ width: 1366, height: 768 });
  await login(page, 'manager');
  await page.getByRole('button', { name: 'বাং' }).click();
  await page.goto('/dashboard');
  await expect(page.getByTestId('kpi-sales')).toBeVisible();
  await settle(page);
  await page.screenshot({ path: `${OUT}/dashboard-bn-1366.png` });
  await page.goto('/reports');
  await settle(page);
  await page.screenshot({ path: `${OUT}/hub-bn-1366.png` });
  await page.goto('/reports/sales-summary?range=month');
  await settle(page);
  await page.screenshot({ path: `${OUT}/sales-summary-bn-1366.png` });
  // The language choice is saved on the account, so put it back for the next run.
  const en = page.getByRole('group', { name: /Language|ভাষা/ }).getByRole('button', { name: 'EN' });
  await en.click();
  await expect(en).toHaveAttribute('aria-pressed', 'true');
});
