import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { pick } from './catalogHelpers';
import { login, toastText } from './helpers';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const BACKEND = path.resolve(HERE, '../../backend');
const LOGO = readFileSync(path.resolve(BACKEND, 'public/assets/brand/orbin-logo.jpg'));

const RUN = Date.now().toString(36).toUpperCase();
const TRX = `E2E${RUN}`;

interface Seed { name: string; skus: Record<string, string> }
let seed: Seed;

/** Saves a download and reads the PDF back with poppler: text with layout, and the raw bytes for the logo check. */
async function readPdf(download: import('@playwright/test').Download): Promise<{ name: string; text: string; bytes: Buffer }> {
  const file = path.join(tmpdir(), `e2e-${Date.now()}-${Math.random().toString(36).slice(2)}.pdf`);
  await download.saveAs(file);
  const text = execFileSync('pdftotext', ['-layout', file, '-'], { encoding: 'utf8' });
  const bytes = readFileSync(file);
  writeFileSync(file, '');
  return { name: download.suggestedFilename(), text, bytes };
}

/** Fetches a protected PDF from inside the page, the way the browser does it (cookie session and origin included). */
async function pdfHead(page: Page, url: string): Promise<{ status: number; head: string }> {
  return page.evaluate(async (u) => {
    const r = await fetch(u, { credentials: 'include' });
    return { status: r.status, head: new TextDecoder().decode((await r.arrayBuffer()).slice(0, 4)) };
  }, url);
}

async function switchTo(page: Page, role: Parameters<typeof login>[1]): Promise<void> {
  await page.context().clearCookies();
  await login(page, role);
}

/** The cashier's till is open: the first visit asks for the opening float. */
async function openTill(page: Page): Promise<void> {
  await page.goto('/pos');
  const gate = page.getByRole('button', { name: 'Open shift' });
  await gate.or(page.getByTestId('pos-terminal')).first().waitFor();
  if (await gate.isVisible()) {
    await page.getByLabel(/^Opening float/).fill('1000');
    await gate.click();
  }
  await expect(page.getByTestId('pos-terminal')).toBeVisible();
}

async function addBySku(page: Page, sku: string): Promise<void> {
  await page.getByTestId('pos-search').fill(sku);
  await page.getByTestId('pos-search').press('Enter');
  await expect(page.getByTestId('cart-line').filter({ hasText: sku })).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('P3 POS and sales gate', () => {
  test.beforeAll(() => {
    const out = execFileSync('php', ['artisan', 'tinker', `--execute=require '${path.resolve(HERE, 'fixtures/pos-seed.php')}';`], { cwd: BACKEND, encoding: 'utf8' });
    const line = out.split('\n').find((l) => l.startsWith('POSSEED'));
    if (!line) throw new Error(`fixture failed: ${out}`);
    seed = JSON.parse(line.slice('POSSEED'.length)) as Seed;
  });

  test('vehicle finder → two sizes → split payment → invoice downloads and is correct', async ({ page }) => {
    await login(page, 'cashier');
    await openTill(page);
    await pick(page, 'Make', 'Toyota');
    await pick(page, 'Model', 'Axio');
    await pick(page, 'Year', '2015');
    await page.getByRole('button', { name: /^Right/ }).click();
    const card = page.getByRole('article', { name: seed.name });
    await expect(card).toBeVisible();
    await expect(card).toHaveCount(1);
    await expect(card.getByRole('button', { name: /7x4 · L/ })).toHaveCount(0); // the left-hand mirror is not offered for the right side
    await card.getByRole('button', { name: /Add size 7x4 · R, 10 in stock/ }).click();
    await card.getByRole('button', { name: /Add size 8x5 · R/ }).click();
    await expect(page.getByTestId('cart-line')).toHaveCount(2);
    await expect(page.getByText('৳ 2,500.00').first()).toBeVisible();

    await page.getByTestId('pay-button').click();
    await page.getByRole('textbox', { name: 'Amount', exact: true }).fill('1500');
    await page.getByRole('button', { name: 'Add payment' }).click();
    await expect(page.getByTestId('remaining')).toHaveText('৳ 1,000.00');
    await page.getByRole('radio', { name: 'bKash' }).click();
    await page.getByRole('button', { name: 'Fill remaining' }).click();
    await page.getByLabel(/^Sender number/).fill('01712345678');
    await page.getByLabel(/^TrxID/).fill(TRX.toLowerCase());
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('complete-sale').click()]);

    await expect(page.getByTestId('success-screen')).toBeVisible();
    const invoiceNo = (await page.getByTestId('success-invoice').textContent()) ?? '';
    expect(invoiceNo).toMatch(/^ORB-INV-\d{4}-\d{6}$/);
    await expect(page.getByTestId('success-amount')).toHaveText('৳ 2,500.00');
    const pdf = await readPdf(download);
    expect(pdf.name).toBe(`Invoice_${invoiceNo}_Walk-in-Customer.pdf`);
    expect(pdf.bytes.subarray(0, 4).toString()).toBe('%PDF');
    expect(pdf.bytes.includes(LOGO.subarray(2000, 2600))).toBe(true); // the original logo file, embedded unchanged
    for (const needle of [invoiceNo, 'Orbin Global', '12 Mirror Lane', 'INVOICE', 'PAID', seed.name, '7x4', '8x5', 'GRAND TOTAL', '2,500.00', 'Taka Two Thousand Five Hundred Only', 'bKash', TRX, 'Cash', 'Trust. Growth. Excellence.']) {
      expect(pdf.text, `the PDF should show ${needle}`).toContain(needle);
    }
    // The fallback button downloads it again.
    const [again] = await Promise.all([page.waitForEvent('download'), page.getByTestId('download-again').click()]);
    expect(again.suggestedFilename()).toBe(pdf.name);
    await page.getByTestId('new-sale').click();
    await expect(page.getByTestId('cart-line')).toHaveCount(0);
  });

  test('a manager returns part of that sale and the credit note is made', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/sales');
    await page.getByRole('row').filter({ hasText: 'Completed' }).first().click();
    await expect(page).toHaveURL(/\/sales\/\d+/);
    await expect(page.getByText(seed.name).first()).toBeVisible();
    await page.getByTestId('sale-return').click();
    await page.getByRole('checkbox').first().click();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByTestId('return-submit').click();
    await expect(toastText(page, /Return ORB-SR-\d{4}-\d{5} posted/)).toBeVisible();
    await expect(page.getByText('Partly returned').first()).toBeVisible();
    await page.goto('/sales/returns');
    await expect(page.getByRole('row').filter({ hasText: 'Posted' }).first()).toBeVisible();
    expect(await pdfHead(page, (await page.getByRole('link', { name: 'Credit note' }).first().getAttribute('href')) as string)).toEqual({ status: 200, head: '%PDF' });
  });

  test('scan a barcode, sell for cash, then a manager voids it and the verify page says VOID', async ({ page }) => {
    await login(page, 'cashier');
    await openTill(page);
    await addBySku(page, seed.skus.L7x4 as string);
    await page.getByTestId('pay-button').click();
    await page.getByLabel('Cash received').fill('2000');
    await expect(page.getByTestId('change-due')).toHaveText('৳ 1,000.00');
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('complete-sale').click()]);
    await expect(page.getByTestId('success-change')).toContainText('1,000.00');
    const invoiceNo = ((await page.getByTestId('success-invoice').textContent()) ?? '').trim();
    expect((await readPdf(download)).text).toContain('Change returned');
    await page.getByTestId('new-sale').click();

    await switchTo(page, 'manager');
    await page.goto(`/sales?search=${invoiceNo}`);
    await page.getByRole('row').filter({ hasText: invoiceNo }).click();
    const token = await page.evaluate(async (no) => ((await (await fetch(`/api/v1/sales?search=${no}`)).json()) as { data: { verify_token: string }[] }).data[0]?.verify_token, invoiceNo);
    await page.getByTestId('sale-void').click();
    await page.getByLabel(/^Reason/).fill('customer changed their mind');
    await page.getByTestId('void-confirm').click();
    await expect(toastText(page, `${invoiceNo} voided`)).toBeVisible();
    await expect(page.getByText('VOID', { exact: true }).first()).toBeVisible();
    await page.context().clearCookies();
    await page.goto(`/verify/${token}`);
    await expect(page.getByText('VOID', { exact: true })).toBeVisible();
    await expect(page.getByText(invoiceNo)).toBeVisible();
    await expect(page.getByText('Walk-in')).toHaveCount(0);
  });

  test('hold a bill, find it again, and resume it', async ({ page }) => {
    await login(page, 'cashier');
    await openTill(page);
    await addBySku(page, seed.skus.R7x4 as string);
    await page.keyboard.press('F10');
    await expect(page.getByTestId('cart-line')).toHaveCount(0);
    await page.getByRole('button', { name: /Held bills: 1/ }).click();
    await page.getByRole('button', { name: 'Resume' }).click();
    await expect(page.getByTestId('cart-line')).toHaveCount(1);
    await expect(page.getByRole('button', { name: /Held bills: 0/ })).toBeVisible();
  });

  test('a big discount needs a manager PIN; a wrong PIN is refused and the right one completes the sale', async ({ page }) => {
    await login(page, 'cashier');
    await openTill(page);
    await addBySku(page, seed.skus.R7x4 as string);
    await page.keyboard.press('F8');
    await page.getByLabel('Value').fill('20');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText(/More than 5% off/)).toBeVisible();
    await page.getByTestId('pay-button').click();
    await page.getByTestId('complete-sale').click();
    await expect(page.getByRole('heading', { name: 'Manager approval' })).toBeVisible();
    await page.getByLabel(/^Manager PIN/).fill('0000');
    await page.getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByText('PIN is incorrect.')).toBeVisible();
    await page.getByLabel(/^Manager PIN/).fill('2345');
    await page.getByLabel(/^Reason/).fill('regular customer');
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Approve' }).click()]);
    await expect(page.getByTestId('success-amount')).toHaveText('৳ 800.00');
    expect((await readPdf(download)).text).toContain('800.00');
    await page.getByTestId('new-sale').click();
  });

  test('a new customer buys on credit and the due shows on their page', async ({ page }) => {
    await login(page, 'cashier');
    await openTill(page);
    await addBySku(page, seed.skus.R8x5 as string);
    await page.keyboard.press('F4');
    await page.getByRole('button', { name: 'New customer' }).click();
    await page.getByLabel(/^Name/).fill('E2E Garage');
    const phone = `017${String(Date.now()).slice(-8)}`;
    await page.getByLabel(/^Phone/).fill(phone);
    await page.getByRole('button', { name: 'Add and select' }).click();
    await expect(page.getByTestId('cart-customer')).toHaveText('E2E Garage');
    await page.getByTestId('pay-button').click();
    await page.getByRole('textbox', { name: 'Amount', exact: true }).fill('500');
    await expect(page.getByText(/৳ 1,000.00 will be recorded as due for E2E Garage/)).toBeVisible();
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('complete-sale').click()]);
    const pdf = await readPdf(download);
    expect(pdf.text).toContain('PARTIAL');
    expect(pdf.text).toContain('E2E Garage');
    expect(pdf.name).toContain('E2E-Garage');
    await page.getByRole('link', { name: 'View invoice' }).click();
    await page.getByRole('link', { name: 'E2E Garage' }).click();
    await expect(page.getByText('Due').first()).toBeVisible();
    await expect(page.getByText('৳ 1,000.00').first()).toBeVisible();
  });

  test('keyboard: F9 opens payment, Esc closes it, "/" focuses the search', async ({ page }) => {
    await login(page, 'cashier');
    await openTill(page);
    await addBySku(page, seed.skus.R7x4 as string);
    await page.locator('body').click({ position: { x: 5, y: 400 } });
    await page.keyboard.press('F9');
    await expect(page.getByRole('dialog', { name: 'Payment' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: 'Payment' })).toHaveCount(0);
    await page.keyboard.press('/');
    await expect(page.getByTestId('pos-search')).toBeFocused();
    await page.getByTestId('cart-line').first().getByRole('button', { name: /Remove/ }).click();
    await page.getByRole('button', { name: /Undo remove/ }).click();
    await expect(page.getByTestId('cart-line')).toHaveCount(1);
  });

  test('closing the shift counts the drawer and keeps a Z-report', async ({ page }) => {
    await login(page, 'cashier');
    await page.goto('/shifts');
    await expect(page.getByTestId('current-shift')).toBeVisible();
    await page.getByTestId('close-shift').click();
    await page.getByLabel('৳ 1000', { exact: true }).fill('1');
    await expect(page.getByTestId('variance')).toBeVisible();
    await page.getByTestId('close-shift-confirm').click();
    await expect(toastText(page, 'Shift closed')).toBeVisible();
    await page.getByRole('row').filter({ hasText: /ORB-SH-/ }).filter({ hasText: 'Closed' }).first().click();
    const href = await page.getByRole('link', { name: 'Z-report' }).getAttribute('href');
    expect(await pdfHead(page, href as string)).toEqual({ status: 200, head: '%PDF' });
  });

  test('roles see only what their permissions allow', async ({ page }) => {
    await login(page, 'storekeeper');
    await page.goto('/pos');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();
    await page.goto('/sales');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();
    await switchTo(page, 'cashier');
    await page.goto('/sales');
    await expect(page.getByRole('link', { name: 'Warranty' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Void' })).toHaveCount(0);
  });
});
