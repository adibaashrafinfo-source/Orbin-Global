import { expect, test, type Page } from '@playwright/test';
import { importProduct, pick } from './catalogHelpers';
import { login, toastText } from './helpers';

const stamp = Date.now().toString(36).toUpperCase();
const CODE = `STK-${stamp}`;
const SKU_A = `${CODE}-A`;
const SKU_B = `${CODE}-B`;
const SUPPLIER = `Taiwan Mirror ${stamp}`;

/** Search for a variant in the picker on the current page and add the first hit. */
async function addVariant(page: Page, sku: string): Promise<void> {
  await page.getByLabel(/Find a variant/).first().fill(sku);
  await page.getByRole('list', { name: 'Search results' }).getByRole('button').filter({ hasText: sku }).first().click();
}

/** Sign in as another role: a signed-in browser is bounced away from the login page, so drop the session first. */
async function switchTo(page: Page, role: Parameters<typeof login>[1]): Promise<void> {
  await page.context().clearCookies();
  await login(page, role);
}

test.describe.configure({ mode: 'serial' });

test.describe('P2 inventory and purchasing gate', () => {
  test('a goods receipt updates stock, average cost and the supplier balance, with landed cost as worked out by hand', async ({ page }) => {
    await login(page, 'super_admin');
    await importProduct(page, CODE, [{ sku: SKU_A, position: 'right', width: '180' }, { sku: SKU_B, position: 'left', width: '180' }]);

    // Supplier with an opening balance of 5,000.
    await page.goto('/purchases/suppliers');
    await page.getByRole('button', { name: 'Add supplier' }).first().click();
    await page.getByLabel(/^Supplier name/).fill(SUPPLIER);
    await page.getByLabel('Opening balance').fill('5000');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('cell', { name: new RegExp(SUPPLIER) })).toBeVisible();

    // Purchase order: A 10 × 100 and B 30 × 200.
    await page.goto('/purchases/orders/new');
    await pick(page, /^Supplier/, SUPPLIER);
    await addVariant(page, SKU_A);
    await addVariant(page, SKU_B);
    await page.getByLabel(`${SKU_A} Ordered`).fill('10');
    await page.getByLabel(`${SKU_A} Unit cost`).fill('100');
    await page.getByLabel(`${SKU_B} Ordered`).fill('30');
    await page.getByLabel(`${SKU_B} Unit cost`).fill('200');
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/purchases\/orders\/\d+$/);
    await page.getByRole('button', { name: 'Approve order' }).click();
    await expect(page.getByText('Approved').first()).toBeVisible();

    // Receive against the order; freight 4,000 billed by the supplier, spread by quantity (10 : 30).
    await page.getByRole('button', { name: 'Receive goods' }).click();
    await expect(page).toHaveURL(/\/purchases\/receipts\/new\?po=\d+/);
    await pick(page, /^Warehouse/, 'Showroom');
    await pick(page, 'Spread extra costs by', 'Quantity');
    await page.getByRole('button', { name: 'Add extra cost' }).click();
    await page.getByLabel('Amount').fill('4000');
    const panel = page.getByRole('table', { name: 'Landed cost' });
    // 10 pcs: (10 × 100 + 1,000) / 10 = 200 · 30 pcs: (30 × 200 + 3,000) / 30 = 300
    await expect(panel.getByRole('row', { name: new RegExp(SKU_A) })).toContainText('200.00');
    await expect(panel.getByRole('row', { name: new RegExp(SKU_B) })).toContainText('300.00');
    await expect(page.getByText(/Landed total/).locator('..')).toContainText('11,000.00');
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/purchases\/receipts\/\d+$/);
    await page.getByRole('button', { name: 'Post receipt' }).click();
    await expect(toastText(page, 'Receipt posted. Stock, cost and the supplier balance are updated.')).toBeVisible();
    await expect(page.getByText('Posted').first()).toBeVisible();

    // Stock: A has 10, B has 30; the stock card shows the receipt.
    await page.goto('/inventory');
    await page.getByRole('main').getByLabel('Search').fill(CODE);
    await expect(page.getByRole('row', { name: new RegExp(SKU_A) })).toContainText('10');
    await expect(page.getByRole('row', { name: new RegExp(SKU_B) })).toContainText('30');
    await page.getByRole('row', { name: new RegExp(SKU_A) }).click();
    await expect(page.getByRole('row', { name: /Purchase receipt/ })).toContainText('+10');

    // Supplier balance: 5,000 opening + 7,000 goods + 4,000 billed freight = 16,000.
    await page.goto('/purchases/suppliers');
    await page.getByRole('main').getByLabel('Search').fill(SUPPLIER);
    await expect(page.getByRole('row', { name: new RegExp(SUPPLIER) })).toContainText('16,000.00');

    // The product list now shows the stock too.
    await page.goto('/products');
    await page.getByRole('main').getByLabel('Search').fill(CODE);
    await expect(page.getByRole('link', { name: new RegExp(`Stock Mirror ${CODE}`) })).toContainText('40 in stock');
  });

  test('negative stock is blocked and a big adjustment needs approval', async ({ page }) => {
    await login(page, 'storekeeper');
    await page.goto('/inventory/adjustments/new');
    await pick(page, /^Warehouse/, 'Showroom');
    await pick(page, /^Reason/, 'Breakage');
    await addVariant(page, SKU_A);
    await page.getByLabel(`${SKU_A} Change`).fill('-50');
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/inventory\/adjustments\/\d+$/);
    // Over the limit and the storekeeper cannot approve: the only way forward is to ask.
    await expect(page.getByText('Needs approval').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send for approval' })).toBeVisible();
    await page.getByRole('button', { name: 'Send for approval' }).click();
    await expect(page.getByText('Waiting for approval').first()).toBeVisible();

    // The manager approves; there are only 10 on the shelf, so the books refuse.
    await switchTo(page, 'manager');
    await page.goto('/inventory/adjustments');
    await page.getByRole('row', { name: /Waiting for approval/ }).first().click();
    await page.getByRole('button', { name: 'Approve and post' }).first().click();
    await expect(page.getByRole('alert').filter({ hasText: /Not enough stock/ })).toContainText('10');
    await page.goto('/inventory');
    await page.getByRole('main').getByLabel('Search').fill(SKU_A);
    await expect(page.getByRole('row', { name: new RegExp(SKU_A) })).toContainText('10');
  });

  test('a transfer that arrives short leaves a draft adjustment at the source', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/inventory/transfers/new');
    await pick(page, /^From warehouse/, 'Showroom');
    await pick(page, /^To warehouse/, 'Godown');
    await addVariant(page, SKU_A);
    await page.getByLabel(`${SKU_A} Sent`).fill('4');
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/inventory\/transfers\/\d+$/);
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    await expect(page.getByText('In transit').first()).toBeVisible();
    await page.getByLabel(`${SKU_A} Received`).fill('3');
    await page.getByRole('button', { name: 'Receive', exact: true }).click();
    await expect(page.getByText('Received').first()).toBeVisible();
    await expect(page.getByText(/Some units did not arrive/)).toBeVisible();
    await page.goto('/inventory/adjustments');
    await page.getByRole('main').getByLabel('Search').fill('ORB-ADJ');
    await expect(page.getByRole('row', { name: /Draft/ }).first()).toBeVisible();
  });

  test('a count corrects the shelf to what was counted', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/inventory/counts/new');
    await pick(page, /^Warehouse/, 'Showroom');
    await page.getByRole('switch', { name: /Blind count/ }).click();
    await page.getByRole('button', { name: 'Create draft' }).click();
    await page.getByRole('button', { name: 'Start counting' }).click();
    await expect(page.getByRole('columnheader', { name: 'System' })).toHaveCount(0); // blind: hidden while counting
    await page.getByLabel(`${SKU_A} Counted`).fill('5');
    await page.getByLabel(`${SKU_B} Counted`).fill('30');
    for (const input of await page.getByRole('textbox', { name: /Counted$/ }).all()) {
      if ((await input.inputValue()) === '') await input.fill('0');
    }
    await page.getByRole('button', { name: 'Finish counting' }).click();
    await expect(page.getByRole('columnheader', { name: 'System' })).toBeVisible();
    await page.getByRole('button', { name: 'Post corrections' }).click();
    await expect(page.getByText('Posted').first()).toBeVisible();
    await page.goto('/inventory');
    await page.getByRole('main').getByLabel('Search').fill(SKU_A);
    await expect(page.getByRole('row', { name: new RegExp(SKU_A) })).toContainText('5');
  });

  test('the integrity check passes on the books these tests just made', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/admin/integrity');
    await page.getByRole('button', { name: 'Run now' }).first().click();
    await expect(page.getByText('All good').first()).toBeVisible();
    await expect(page.getByText('Stock on file equals the sum of movements')).toBeVisible();
    await expect(page.getByText(/problem/)).toHaveCount(0);
  });

  test('roles see only what their permissions allow', async ({ page }) => {
    await login(page, 'storekeeper');
    await page.goto('/inventory');
    await expect(page.getByRole('link', { name: 'Valuation' })).toHaveCount(0); // needs the cost permission
    await expect(page.getByRole('columnheader', { name: 'Average cost' })).toHaveCount(0);
    await page.goto('/inventory/valuation');
    await expect(page.getByText(/Something went wrong|do not have permission/).first()).toBeVisible();
    await switchTo(page, 'cashier');
    await page.goto('/purchases/suppliers');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();
    await page.goto('/admin/integrity');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();
  });
});
