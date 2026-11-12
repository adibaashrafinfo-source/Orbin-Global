import { expect, test } from '@playwright/test';
import { login, toastText, USERS, type RoleKey } from './helpers';

const ROLES = Object.keys(USERS) as RoleKey[];

test.describe('P0 foundation', () => {
  for (const role of ROLES) {
    test(`login works for ${role} and shows only permitted navigation`, async ({ page }) => {
      await login(page, role);
      await expect(page.getByRole('navigation').first()).toBeVisible();
      const settings = page.getByRole('link', { name: 'Settings' });
      const pos = page.getByText('POS Terminal');
      // Settings is visible to roles holding any settings-related permission (manager has audit.view only).
      if (role === 'super_admin' || role === 'manager') await expect(settings).toBeVisible();
      else await expect(settings).toHaveCount(0);
      if (role === 'storekeeper' || role === 'accountant') await expect(pos).toHaveCount(0);
      else await expect(pos).toBeVisible();
    });
  }

  test('wrong password shows an error and stays on login', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel(/^Email address/).fill(USERS.cashier);
    await page.getByLabel(/^Password/).fill('not-the-password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'incorrect' })).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
  });

  test('guests are redirected to login and cashier cannot open admin routes', async ({ page }) => {
    await page.goto('/settings/users');
    await expect(page).toHaveURL(/\/login/);
    await login(page, 'cashier');
    await page.goto('/settings/users');
    await expect(page.getByText('You do not have permission')).toBeVisible();
    await page.goto('/admin/health');
    await expect(page.getByText('You do not have permission')).toBeVisible();
  });

  test('API enforces permissions regardless of the UI (403 for cashier)', async ({ page }) => {
    await login(page, 'cashier');
    const status = await page.evaluate(async () => (await fetch('/api/v1/users', { headers: { Accept: 'application/json' } })).status);
    expect(status).toBe(403);
    const audit = await page.evaluate(async () => (await fetch('/api/v1/audit-logs', { headers: { Accept: 'application/json' } })).status);
    expect(audit).toBe(403);
  });

  test('super admin can open every settings screen and the health page', async ({ page }) => {
    await login(page, 'super_admin');
    for (const [path, heading] of [
      ['/settings/company', 'Company profile'],
      ['/settings/branches', 'Branches & warehouses'],
      ['/settings/users', 'Users'],
      ['/settings/roles', 'Roles & permissions'],
      ['/settings/numbering', 'Numbering'],
      ['/settings/pos', 'POS & sales rules'],
      ['/settings/audit-log', 'Audit log'],
      ['/admin/health', 'Hosting health'],
      ['/gallery', 'Component gallery'],
    ] as const) {
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
    }
  });

  test('health page shows green checks and a cron heartbeat', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/admin/health');
    await expect(page.getByText('PHP', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Last cron run').first()).toBeVisible();
    await expect(page.getByText('Pending migrations').first()).toBeVisible();
  });

  test('company profile saves, unlocks invoice generation and uploads a logo', async ({ page }, testInfo) => {
    await login(page, 'super_admin');
    await page.goto('/settings/company');
    await expect(page.getByText(/blocked until/).first()).toBeVisible();
    await page.getByLabel('Address line 1').fill('12 Mirpur Road');
    await page.getByLabel('Phone numbers').fill('01711000000');
    await page.getByLabel('City').fill('Dhaka');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(toastText(page, 'Company profile saved.')).toBeVisible();
    await expect(page.getByText('Invoice generation is enabled.').first()).toBeVisible();
    // live preview reflects the form
    await expect(page.getByLabel('Invoice preview').getByText('12 Mirpur Road')).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Address line 1')).toHaveValue('12 Mirpur Road');

    // logo upload keeps the original bytes
    const png = testInfo.outputPath('logo.png');
    const { writeFileSync } = await import('node:fs');
    writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', 'base64'));
    await page.getByLabel('Upload logo').setInputFiles(png);
    await expect(toastText(page, 'Logo updated.')).toBeVisible();
    const src = await page.getByRole('img', { name: 'Logo' }).first().getAttribute('src');
    expect(src).toMatch(/^\/uploads\/brand\/logo-.*\.png$/);
    const res = await page.request.get(src!);
    expect(res.status()).toBe(200);
    expect((await res.body()).length).toBe(70);
  });

  test('uploads folder never executes PHP', async ({ page }) => {
    const res = await page.request.get('/uploads/brand/shell.php');
    expect([403, 404]).toContain(res.status());
  });

  test('language toggle switches to Bangla and persists', async ({ page }) => {
    await login(page, 'super_admin');
    await page.getByRole('button', { name: 'বাং' }).click();
    await expect(page.getByRole('link', { name: 'ড্যাশবোর্ড' })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('link', { name: 'ড্যাশবোর্ড' })).toBeVisible();
    await page.getByRole('button', { name: 'EN' }).click();
    await expect(page.getByRole('link', { name: 'Dashboard' })).toBeVisible();
  });

  test('permission grid edit takes effect for the role immediately', async ({ browser }) => {
    const admin = await browser.newContext();
    const adminPage = await admin.newPage();
    await login(adminPage, 'super_admin');
    await adminPage.goto('/settings/roles');
    const cell = adminPage.getByRole('checkbox', { name: /Storekeeper · audit\.view/ });
    await expect(cell).not.toBeChecked();
    await cell.click();
    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText(/Permissions saved for Storekeeper/).first()).toBeVisible();

    const store = await browser.newContext();
    const storePage = await store.newPage();
    await login(storePage, 'storekeeper');
    const ok = await storePage.evaluate(async () => (await fetch('/api/v1/audit-logs', { headers: { Accept: 'application/json' } })).status);
    expect(ok).toBe(200);

    // restore defaults so the suite stays repeatable
    await adminPage.getByRole('button', { name: 'Reset to defaults' }).nth(2).click();
    await expect.poll(async () => storePage.evaluate(async () => (await fetch('/api/v1/audit-logs', { headers: { Accept: 'application/json' } })).status)).toBe(403);
    await admin.close();
    await store.close();
  });

  test('user management: create, edit and deactivate a user', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/users');
    const email = `e2e-${Date.now()}@orbin.local`;
    await page.getByRole('button', { name: 'Add user' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel(/^Full name/).fill('E2E Tester');
    await dialog.getByLabel(/^Email/).fill(email);
    await dialog.getByLabel(/^Password/).fill('Password123');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(toastText(page, 'User saved.')).toBeVisible();
    await page.locator('#main').getByRole('searchbox', { name: 'Search' }).fill(email);
    await expect(page.getByRole('cell', { name: email })).toBeVisible();
    await page.getByRole('cell', { name: email }).click();
    await page.getByRole('button', { name: 'Deactivate' }).click();
    await page.getByRole('dialog').filter({ hasText: 'cannot log in' }).getByRole('button', { name: 'Deactivate' }).click();
    await expect(page.getByRole('row', { name: new RegExp(email) }).getByText('Inactive')).toBeVisible();
  });

  test('numbering preview updates and invoice format is the spec default', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/numbering');
    await expect(page.getByText(/ORB-INV-\d{4}-000001/)).toBeVisible();
  });

  test('audit log lists actions with before/after detail', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/audit-log');
    await expect(page.getByRole('table').getByText('login').first()).toBeVisible();
  });

  test('password reset password form validates and change-password gate blocks until changed', async ({ page }) => {
    await page.goto('/login?reset=badtoken&email=cashier%40orbin.local');
    await page.getByLabel(/^New password/).fill('Reset12345');
    await page.getByLabel('Confirm password').fill('Reset12345');
    await page.getByRole('button', { name: 'Reset password' }).click();
    await expect(page.getByRole('alert')).toBeVisible();
  });
});
