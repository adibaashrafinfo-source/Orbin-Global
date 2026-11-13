import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { login } from './helpers';

// Not part of the regression run: `SHOTS=1 npx playwright test phase4-screenshots.spec.ts` refreshes docs/screenshots/p4.
// Run it after phase4-finance.spec.ts so the lists have payments, expenses, transfers and dues to show.
test.skip(!process.env.SHOTS, 'set SHOTS=1 to capture');

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.resolve(HERE, '../../docs/screenshots/p4');
const SIZES = [{ w: 1366, h: 768 }, { w: 1920, h: 1080 }];
const settle = (page: Page) => page.waitForTimeout(900);

interface Seed { customer: string; customerCode: string; invoices: string[]; supplier: string }

/** A fresh customer with three credit invoices, so the receive screen has something to allocate. */
function seedOne(): Seed {
  // The foundation spec uploads a test logo to the company profile; clear it so the screenshots show the real brand logo.
  execFileSync('php', ['artisan', 'tinker', "--execute=App\\Models\\CompanyProfile::current()->forceFill(['logo_url' => null, 'logo_storage_path' => null])->save();"], { cwd: path.resolve(HERE, '../../backend') });
  const out = execFileSync('php', ['artisan', 'tinker', `--execute=require '${path.resolve(HERE, 'fixtures/finance-seed.php')}';`], { cwd: path.resolve(HERE, '../../backend'), encoding: 'utf8' });
  return JSON.parse((out.split('\n').find((l) => l.startsWith('FINSEED')) as string).slice(7)) as Seed;
}

for (const { w, h } of SIZES) {
  test(`phase 4 screenshots ${w}x${h}`, async ({ page }) => {
    test.setTimeout(150_000);
    const seed = seedOne();
    await page.setViewportSize({ width: w, height: h });
    await login(page, 'accountant');

    await page.goto('/ledger/receive');
    await page.getByLabel('Find a customer').fill(seed.customer);
    await page.getByRole('button', { name: new RegExp(seed.customer) }).click();
    await page.getByLabel(/^Amount/).fill('2500');
    await settle(page);
    await page.screenshot({ path: `${OUT}/receive-${w}.png` });

    await page.goto('/ledger/parties');
    await page.getByLabel('Find a customer').fill(seed.customer);
    await page.getByRole('button', { name: new RegExp(seed.customer) }).click();
    await expect(page.getByTestId('statement-table')).toBeVisible();
    await settle(page);
    await page.screenshot({ path: `${OUT}/party-ledger-${w}.png` });

    for (const [name, url] of [['accounts', '/ledger/accounts'], ['expenses', '/ledger/expenses'], ['transfers', '/ledger/transfers'], ['day-book', '/ledger/day-book'], ['dues', '/ledger/dues']] as const) {
      await page.goto(url);
      await settle(page);
      await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
    }
    await page.goto('/ledger/expenses');
    await page.getByTestId('add-expense').click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/expense-dialog-${w}.png` });
    await page.keyboard.press('Escape');

    await page.goto('/ledger/dues');
    await page.locator('#main').getByLabel(/^Search/).fill(seed.customer);
    await page.getByTestId('dues-table').locator('tbody tr', { hasText: seed.customer }).getByRole('button', { name: 'Promise to pay' }).click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/promise-dialog-${w}.png` });
    await page.keyboard.press('Escape');

    await page.context().clearCookies();
    await login(page, 'super_admin');
    await page.goto('/settings/period-lock');
    await settle(page);
    await page.screenshot({ path: `${OUT}/period-lock-${w}.png` });
    await page.goto('/settings/pos');
    await settle(page);
    await page.screenshot({ path: `${OUT}/settings-finance-${w}.png` });
    await page.goto('/customers');
    await page.getByRole('row').filter({ hasText: /CUS-\d{6}/ }).first().click();
    await page.getByRole('tab', { name: 'Payments' }).click();
    await settle(page);
    await page.screenshot({ path: `${OUT}/customer-payments-${w}.png` });

    await page.context().clearCookies();
    await login(page, 'cashier');
    await page.goto('/ledger');
    await settle(page);
    await page.screenshot({ path: `${OUT}/cashier-receive-${w}.png` });
  });
}

test('phase 4 screenshots bangla 1366x768', async ({ page }) => {
  test.setTimeout(120_000);
  seedOne();
  await page.setViewportSize({ width: 1366, height: 768 });
  await login(page, 'accountant');
  await page.getByRole('button', { name: 'বাং' }).click();
  for (const [name, url] of [['receive', '/ledger/receive'], ['accounts', '/ledger/accounts'], ['dues', '/ledger/dues'], ['day-book', '/ledger/day-book']] as const) {
    await page.goto(url);
    await settle(page);
    await page.screenshot({ path: `${OUT}/${name}-bn-1366.png` });
  }
  // The language choice is saved on the account, so put it back for the next run.
  const en = page.getByRole('group', { name: /Language|ভাষা/ }).getByRole('button', { name: 'EN' });
  await en.click();
  await expect(en).toHaveAttribute('aria-pressed', 'true');
});
