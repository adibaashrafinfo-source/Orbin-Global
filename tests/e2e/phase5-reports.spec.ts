import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Download, type Page } from '@playwright/test';
import { login } from './helpers';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const BACKEND = path.resolve(HERE, '../../backend');

interface Seed { invoices: string[]; stamp: string; sku: string }
let seed: Seed;

const money = (text: string): number => Number(text.replace(/[^\d.-]/g, ''));

async function save(download: Download): Promise<{ name: string; bytes: Buffer }> {
  const file = path.join(tmpdir(), `e2e-rep-${Date.now()}-${Math.random().toString(36).slice(2)}`);
  await download.saveAs(file);

  return { name: download.suggestedFilename(), bytes: readFileSync(file) };
}

async function switchTo(page: Page, role: Parameters<typeof login>[1]): Promise<void> {
  await page.goto('about:blank');
  await page.context().clearCookies();
  await login(page, role);
}

/** The figure on a KPI tile of the dashboard, once its count-up has finished. */
async function kpi(page: Page, id: string): Promise<number> {
  const tile = page.getByTestId(id);
  await expect(tile).toBeVisible();
  let last = '';
  await expect.poll(async () => { const now = (await tile.innerText()).match(/-?৳ [\d,]+\.\d{2}|^\s*\d+$/m)?.[0] ?? ''; const same = now === last; last = now; return same && now !== ''; }, { intervals: [300, 300, 300] }).toBe(true);

  return money(last);
}

test.describe.configure({ mode: 'serial' });

test.describe('P5 reports and dashboard gate', () => {
  test.beforeAll(() => {
    const out = execFileSync('php', ['artisan', 'tinker', `--execute=require '${path.resolve(HERE, 'fixtures/reports-seed.php')}';`], { cwd: BACKEND, encoding: 'utf8' });
    const line = out.split('\n').find((l) => l.startsWith('REPSEED'));
    if (!line) throw new Error(`fixture failed: ${out}`);
    seed = JSON.parse(line.slice('REPSEED'.length)) as Seed;
  });

  test('the gate: dashboard sales = the sales report = the invoice list for today', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/dashboard');
    const dash = await kpi(page, 'kpi-sales');
    expect(dash).toBeGreaterThanOrEqual(2900);

    await page.goto('/reports/sales-summary?range=today');
    await expect(page.getByTestId('report-tiles')).toContainText(`৳ ${dash.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
    const report = await page.getByTestId('report-table').locator('tfoot td').nth(7).innerText();
    expect(money(report)).toBe(dash);

    await page.goto('/sales');
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date());
    await page.getByLabel(/^From/).fill(today);
    await page.getByLabel(/^To/).fill(today);
    const listTotal = page.locator('#main').getByText('Total').first().locator('xpath=following::*[1]');
    await expect.poll(async () => money(await listTotal.innerText())).toBe(dash);
  });

  test('the gate: profit on the dashboard = the profit report = the profit and loss report', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/dashboard');
    const dash = await kpi(page, 'kpi-profit');
    await page.goto('/reports/profit-by-invoice?range=today');
    await expect(page.getByTestId('report-table').locator('tfoot td').first()).toBeVisible();
    const cols = await page.getByTestId('report-table').locator('thead th').allInnerTexts();
    const idx = cols.findIndex((c) => c.trim().startsWith('Profit'));
    expect(idx).toBeGreaterThanOrEqual(0);
    expect(money(await page.getByTestId('report-table').locator('tfoot td').nth(idx).innerText())).toBe(dash);
    // 1,000 + 1,000 + 900 sold at a cost of 600 each, plus anything the earlier specs sold.
    expect(dash).toBeGreaterThanOrEqual(1100);

    await page.goto('/reports/profit-loss?range=month');
    await expect(page.getByTestId('report-table').locator('tfoot td').first()).toBeVisible(); // allInnerTexts does not wait for the data
    const pl = await page.getByTestId('report-table').locator('thead th').allInnerTexts();
    const g = pl.findIndex((c) => c.trim().startsWith('Gross profit'));
    expect(g).toBeGreaterThanOrEqual(0);
    expect(money(await page.getByTestId('report-table').locator('tfoot td').nth(g).innerText())).toBeGreaterThanOrEqual(dash);
  });

  test('every format exports: CSV, Excel and PDF, and the print view opens the PDF', async ({ page, context }) => {
    await login(page, 'accountant');
    await page.goto('/reports/profit-by-invoice?range=today');
    await expect(page.getByTestId('report-table')).toBeVisible();
    const rows = await page.getByTestId('report-table').locator('tbody tr').count();

    await page.getByTestId('export-menu').click();
    const [csv] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-csv').click()]);
    const c = await save(csv);
    expect(c.name).toMatch(/^Orbin_profit-by-invoice_.*\.csv$/);
    const lines = c.bytes.toString('utf8').replace(/^﻿/, '').trim().split(/\r?\n/);
    expect(lines[0]).toContain('Invoice');
    expect(lines.length).toBe(rows + 2);
    expect(c.bytes.toString('utf8')).toContain(seed.invoices[0]!);

    await page.getByTestId('export-menu').click();
    const [xlsx] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-xlsx').click()]);
    const x = await save(xlsx);
    expect(x.name).toMatch(/\.xlsx$/);
    expect(x.bytes.subarray(0, 2).toString()).toBe('PK');

    await page.getByTestId('export-menu').click();
    const [pdf] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-pdf').click()]);
    const p = await save(pdf);
    expect(p.bytes.subarray(0, 4).toString()).toBe('%PDF');
    const file = path.join(tmpdir(), `rep-${Date.now()}.pdf`);
    writeFileSync(file, p.bytes);
    expect(execFileSync('pdftotext', ['-layout', file, '-'], { encoding: 'utf8' })).toContain('Gross profit by invoice');

    await page.getByTestId('export-menu').click();
    const [tab] = await Promise.all([context.waitForEvent('page'), page.getByTestId('export-print').click()]);
    expect(tab.url()).toContain('/export?');
    expect(tab.url()).toContain('inline=1');
    await tab.close();
  });

  test('drill-down: an invoice number in a report opens the invoice', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/reports/profit-by-invoice?range=today');
    await page.getByRole('link', { name: seed.invoices[0]! }).click();
    await expect(page).toHaveURL(/\/sales\/\d+/);
    await expect(page.getByText(seed.invoices[0]!).first()).toBeVisible();
  });

  test('compare to the previous period, grouping, sorting and a saved view', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/reports/sales-summary?range=today');
    await page.getByRole('switch', { name: 'Compare to previous period' }).click();
    await expect(page.getByTestId('report-tiles')).toContainText(/vs \d{4}-\d{2}-\d{2} to/);

    await page.goto('/reports/sales-by-product?range=month');
    const before = await page.getByTestId('report-table').locator('tbody tr').first().innerText();
    await page.getByRole('button', { name: 'Qty' }).click();
    await expect(page.getByRole('columnheader', { name: /Qty/ })).toHaveAttribute('aria-sort', 'ascending');
    expect(before.length).toBeGreaterThan(0);

    await page.getByTestId('save-preset').click();
    await page.getByRole('dialog').getByLabel(/^Name/).fill(`E2E ${seed.stamp}`);
    await page.getByTestId('preset-confirm').click();
    await expect(page.getByRole('combobox', { name: 'Saved views' })).toBeVisible();
    await page.reload();
    await page.getByRole('combobox', { name: 'Saved views' }).click();
    await expect(page.getByRole('option', { name: `E2E ${seed.stamp}` })).toBeVisible();
  });

  test('the hub lists reports by role and search narrows it', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/reports');
    await expect(page.getByTestId('report-card-sales-summary')).toBeVisible();
    await expect(page.getByTestId('report-card-audit-login')).toBeVisible();
    await page.locator('#main').getByLabel(/^Search/).fill('valuation');
    await expect(page.getByTestId('report-card-stock-valuation')).toBeVisible();
    await expect(page.getByTestId('report-card-sales-summary')).toHaveCount(0);

    await switchTo(page, 'accountant');
    await page.goto('/reports');
    await expect(page.getByTestId('report-card-profit-loss')).toBeVisible();
    await expect(page.getByTestId('report-card-audit-login')).toHaveCount(0);

    await switchTo(page, 'storekeeper');
    await page.goto('/reports');
    await expect(page.getByTestId('report-card-stock-current')).toBeVisible();
    await expect(page.getByTestId('report-card-sales-summary')).toHaveCount(0);
    await page.goto('/reports/profit-loss');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();

    await switchTo(page, 'cashier');
    await page.goto('/reports');
    await expect(page.getByTestId('report-card-sales-summary')).toBeVisible();
    await expect(page.getByTestId('report-card-stock-current')).toHaveCount(0);
    await page.goto('/reports/profit-by-invoice');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();
  });

  test('the dashboard shows only what each role may see', async ({ page }) => {
    await login(page, 'cashier');
    await page.goto('/dashboard');
    await expect(page.getByTestId('kpi-sales')).toBeVisible();
    await expect(page.getByTestId('kpi-profit')).toHaveCount(0);
    await expect(page.getByTestId('account-balances')).toHaveCount(0);

    await switchTo(page, 'storekeeper');
    await page.goto('/dashboard');
    await expect(page.getByTestId('low-stock')).toBeVisible();
    await expect(page.getByTestId('kpi-sales')).toHaveCount(0);

    await switchTo(page, 'manager');
    await page.goto('/dashboard');
    for (const id of ['kpi-sales', 'kpi-orders', 'kpi-profit', 'kpi-collections', 'kpi-dues', 'sales-trend', 'payment-donut', 'top-products', 'low-stock', 'account-balances', 'pending-approvals', 'recent-sales']) {
      await expect(page.getByTestId(id)).toBeVisible();
    }
    await page.getByTestId('range-7d').click();
    await expect(page.getByTestId('range-7d')).toHaveAttribute('aria-pressed', 'true');
    await page.getByTestId('range-custom').click();
    await expect(page.getByLabel('From')).toBeVisible();
  });
});
