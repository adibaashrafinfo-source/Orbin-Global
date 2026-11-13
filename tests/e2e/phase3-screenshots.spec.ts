import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { pick } from './catalogHelpers';
import { login } from './helpers';

// Not part of the regression run: `SHOTS=1 npx playwright test phase3-screenshots.spec.ts` refreshes docs/screenshots/p3.
// Run it after phase3-pos.spec.ts so the lists have invoices, returns and shifts to show.
test.skip(!process.env.SHOTS, 'set SHOTS=1 to capture');

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.resolve(HERE, '../../docs/screenshots/p3');
const SIZES = [{ w: 1366, h: 768 }, { w: 1920, h: 1080 }];
const settle = (page: Page) => page.waitForTimeout(900);

async function sku(): Promise<{ name: string; skus: Record<string, string> }> {
  const out = execFileSync('php', ['artisan', 'tinker', `--execute=require '${path.resolve(HERE, 'fixtures/pos-seed.php')}';`], { cwd: path.resolve(HERE, '../../backend'), encoding: 'utf8' });
  return JSON.parse((out.split('\n').find((l) => l.startsWith('POSSEED')) as string).slice(7)) as { name: string; skus: Record<string, string> };
}

async function till(page: Page): Promise<void> {
  await page.goto('/pos');
  const gate = page.getByRole('button', { name: /Open shift|শিফট খুলুন/ });
  await gate.or(page.getByTestId('pos-terminal')).first().waitFor();
  if (await gate.isVisible()) await gate.click();
  await expect(page.getByTestId('pos-terminal')).toBeVisible();
}

for (const { w, h } of SIZES) {
  test(`phase 3 screenshots ${w}x${h}`, async ({ page, context }) => {
    test.setTimeout(120_000);
    const seed = await sku();
    await page.setViewportSize({ width: w, height: h });
    // Admin: lists and detail pages (a manager has every sales screen).
    await login(page, 'cashier');
    await till(page);
    await pick(page, 'Make', 'Toyota');
    await pick(page, 'Model', 'Axio');
    await pick(page, 'Year', '2015');
    await page.getByRole('button', { name: /^Right/ }).click();
    await page.getByTestId('pos-search').fill(seed.name); // many test products fit this car; the search narrows to this run's
    const card = page.getByRole('article', { name: seed.name });
    await expect(card).toBeVisible();
    await card.getByRole('button', { name: /Add size 7x4/ }).click();
    await card.getByRole('button', { name: /Add size 8x5/ }).click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/pos-${w}.png` });
    // A quotation from the same cart, to have one to show.
    const [popup] = await Promise.all([context.waitForEvent('page'), page.getByRole('button', { name: 'Quotation' }).click()]);
    await popup.close();
    await page.getByTestId('pos-search').fill(seed.name); // a new sale clears the search
    await expect(card).toBeVisible();
    await card.getByRole('button', { name: /Add size 7x4/ }).click();
    await card.getByRole('button', { name: /Add size 8x5/ }).click();
    await page.getByTestId('pay-button').click();
    await page.getByLabel('Cash received').fill('3000');
    await settle(page);
    await page.screenshot({ path: `${OUT}/pay-drawer-${w}.png` });
    await page.getByTestId('complete-sale').click();
    await expect(page.getByTestId('success-screen')).toBeVisible();
    await settle(page);
    await page.screenshot({ path: `${OUT}/success-${w}.png` });
    await page.getByTestId('new-sale').click();

    await page.context().clearCookies();
    await login(page, 'manager');
    for (const [name, url] of [['sales', '/sales'], ['returns', '/sales/returns'], ['quotations', '/quotations'], ['shifts', '/shifts'], ['customers', '/customers']] as const) {
      await page.goto(url);
      await settle(page);
      await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
    }
    await page.goto('/sales');
    await page.getByRole('row').filter({ hasText: 'Completed' }).first().click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/sale-detail-${w}.png` });
    await page.getByTestId('sale-return').click();
    await page.getByRole('checkbox').first().click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/return-wizard-${w}.png` });
    await page.keyboard.press('Escape');
    await page.goto('/customers');
    await page.getByRole('row').filter({ hasText: /CUS-\d{6}/ }).first().click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/customer-${w}.png` });
    await page.goto('/quotations');
    await page.getByRole('row').filter({ hasText: /ORB-QT/ }).first().click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/quotation-${w}.png` });
    await page.goto('/warranty');
    await settle(page);
    await page.screenshot({ path: `${OUT}/warranty-${w}.png` });
  });
}

test('phase 3 screenshots bangla 1366x768', async ({ page }) => {
  test.setTimeout(120_000);
  await sku();
  await page.setViewportSize({ width: 1366, height: 768 });
  await login(page, 'cashier');
  await page.getByRole('button', { name: 'বাং' }).click();
  await till(page);
  await settle(page);
  await page.screenshot({ path: `${OUT}/pos-bn-1366.png` });
  await page.goto('/sales');
  await settle(page);
  await page.screenshot({ path: `${OUT}/sales-bn-1366.png` });
  await page.goto('/shifts');
  await settle(page);
  await page.screenshot({ path: `${OUT}/shifts-bn-1366.png` });
  // The language choice is saved on the account, so put it back for the next run.
  const en = page.getByRole('group', { name: /Language|ভাষা/ }).getByRole('button', { name: 'EN' });
  await en.click();
  await expect(en).toHaveAttribute('aria-pressed', 'true');
});
