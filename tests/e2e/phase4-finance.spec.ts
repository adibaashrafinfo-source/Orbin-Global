import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Download, type Page } from '@playwright/test';
import { pick } from './catalogHelpers';
import { login } from './helpers';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const BACKEND = path.resolve(HERE, '../../backend');

interface Seed { customer: string; customerCode: string; invoices: string[]; supplier: string; grn: string; stamp: string }
let seed: Seed;

const todayDhaka = (): string => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date());
const tomorrowDhaka = (): string => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date(Date.now() + 86_400_000));
const taka = (n: number): string => `৳ ${n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const num = (text: string): number => Number(text.replace(/[^\d.-]/g, ''));

/** Saves a download and reads the PDF back with poppler. */
async function readPdf(download: Download): Promise<{ name: string; text: string }> {
  const file = path.join(tmpdir(), `e2e-fin-${Date.now()}-${Math.random().toString(36).slice(2)}.pdf`);
  await download.saveAs(file);
  const text = execFileSync('pdftotext', ['-layout', file, '-'], { encoding: 'utf8' });
  const name = download.suggestedFilename();
  writeFileSync(file, readFileSync(file).subarray(0, 0));
  return { name, text };
}

async function switchTo(page: Page, role: Parameters<typeof login>[1]): Promise<void> {
  // Leave the app first: a request still in flight from the old page could set the old session cookie again after it was cleared.
  await page.goto('about:blank');
  await page.context().clearCookies();
  await login(page, role);
}

/** Opens the receive screen with the seed customer picked. */
async function pickCustomer(page: Page): Promise<void> {
  await page.goto('/ledger/receive');
  await page.getByLabel('Find a customer').fill(seed.customer);
  await page.getByRole('button', { name: new RegExp(seed.customer) }).click();
  await expect(page.getByTestId('picked-party')).toContainText(seed.customer);
}

/** Opens a select inside the open dialog (the page behind it may have a select with the same label) and chooses an option. */
async function pickInDialog(page: Page, label: RegExp | string, option: RegExp | string): Promise<void> {
  await page.getByRole('dialog').getByLabel(label).first().click();
  await page.getByRole('option', { name: option }).first().click();
}

/** The balance shown on an account card. */
async function cardBalance(page: Page, code: string): Promise<number> {
  const card = page.getByTestId(`account-card-${code}`);
  await expect(card).toBeVisible();
  // The balance counts up for a quarter of a second; wait until two reads agree.
  let last = '';
  await expect.poll(async () => { const now = (await card.innerText()).match(/-?৳ [\d,]+\.\d{2}/)?.[0] ?? ''; const same = now === last; last = now; return same && now !== ''; }, { intervals: [300, 300, 300] }).toBe(true);
  return num(last);
}

test.describe.configure({ mode: 'serial' });

test.describe('P4 ledger and finance gate', () => {
  test.beforeAll(() => {
    const out = execFileSync('php', ['artisan', 'tinker', `--execute=require '${path.resolve(HERE, 'fixtures/finance-seed.php')}';`], { cwd: BACKEND, encoding: 'utf8' });
    const line = out.split('\n').find((l) => l.startsWith('FINSEED'));
    if (!line) throw new Error(`fixture failed: ${out}`);
    seed = JSON.parse(line.slice('FINSEED'.length)) as Seed;
  });

  test('AT-014: a receipt settles two and a half invoices oldest first, and the POS dues show in the customer ledger', async ({ page }) => {
    await login(page, 'accountant');
    await pickCustomer(page);
    await expect(page.getByTestId('allocation-panel')).toContainText(seed.invoices[0]!);
    await page.getByLabel(/^Amount/).fill('2500');
    await expect(page.getByTestId(`applied-${seed.invoices[0]}`)).toHaveText(taka(1000));
    await expect(page.getByTestId(`applied-${seed.invoices[1]}`)).toHaveText(taka(1000));
    await expect(page.getByTestId(`applied-${seed.invoices[2]}`)).toHaveText(taka(500));
    await expect(page.getByTestId('advance-amount')).toHaveText(taka(0));
    await page.getByTestId('record-payment').click();

    const done = page.getByTestId('payment-success');
    await expect(done).toBeVisible();
    await expect(done).toContainText(taka(2500));
    const paymentNo = /ORB-PAY-\d{4}-\d{5}/.exec(await done.innerText())?.[0];
    expect(paymentNo).toBeTruthy();
    const [download] = await Promise.all([page.waitForEvent('download'), done.getByRole('button', { name: 'Receipt PDF' }).click()]);
    const pdf = await readPdf(download);
    expect(pdf.name).toBe(`${paymentNo}.pdf`);
    for (const needle of ['PAYMENT RECEIPT', paymentNo!, seed.customer, 'Two Thousand Five Hundred', seed.invoices[0]!, seed.invoices[2]!]) expect(pdf.text).toContain(needle);

    // The customer's ledger: the three invoices the POS left due, then the payment, closing on 500.
    await page.goto('/ledger/parties');
    await page.getByLabel('Find a customer').fill(seed.customer);
    await page.getByRole('button', { name: new RegExp(seed.customer) }).click();
    const table = page.getByTestId('statement-table');
    await expect(table).toBeVisible();
    for (const no of seed.invoices) await expect(table).toContainText(no);
    await expect(table).toContainText(paymentNo!);
    await expect(table.locator('tbody tr').last().locator('td').last()).toHaveText(taka(500));
  });

  test('a manual split cannot exceed what a document owes, and posts exactly what was typed', async ({ page }) => {
    await login(page, 'accountant');
    await pickCustomer(page);
    await page.getByLabel(/^Amount/).fill('300');
    await page.getByRole('switch', { name: 'Split it myself' }).click();
    const field = page.getByLabel(`Amount for ${seed.invoices[2]}`);
    await field.fill('600');
    await expect(page.getByText('That is more than the document still owes.').first()).toBeVisible();
    await expect(page.getByTestId('record-payment')).toBeDisabled();
    await field.fill('300');
    await expect(page.getByTestId('advance-amount')).toHaveText(taka(0));
    await page.getByTestId('record-payment').click();
    await expect(page.getByTestId('payment-success')).toContainText('৳ 300.00 applied');
  });

  test('a TrxID used before needs a manager PIN, and a wrong PIN is refused', async ({ page }) => {
    const trx = `FIN${seed.stamp}`;
    await login(page, 'accountant');
    for (const attempt of [1, 2]) {
      await pickCustomer(page);
      await page.getByLabel(/^Amount/).fill('50');
      await pick(page, 'Method', 'bKash');
      await page.getByLabel(/^Sender number/).fill('01712345678');
      await page.getByLabel(/^TrxID/).fill(trx);
      await page.getByTestId('record-payment').click();
      if (attempt === 1) {
        await expect(page.getByTestId('payment-success')).toBeVisible();
        continue;
      }
      const dialog = page.getByRole('dialog');
      await expect(dialog).toContainText('Manager approval');
      await dialog.getByLabel('Manager PIN').fill('0000');
      await dialog.getByRole('button', { name: 'Approve' }).click();
      await expect(dialog).toContainText(/not a manager's|PIN/i);
      await dialog.getByLabel('Manager PIN').fill('2345');
      await dialog.getByLabel(/^Reason/).fill('customer paid twice by mistake');
      await dialog.getByRole('button', { name: 'Approve' }).click();
      await expect(page.getByTestId('payment-success')).toBeVisible();
    }
  });

  test('expenses and transfers move the account balances, and the day book agrees with the account cards', async ({ page }) => {
    await login(page, 'accountant');
    await page.goto('/ledger/accounts');
    await page.getByTestId('account-card-BKASH-01').click();
    await page.getByRole('button', { name: /^Adjust$/ }).click();
    const dlg = page.getByRole('dialog');
    await dlg.getByLabel(/^Amount/).fill('20000');
    await dlg.getByLabel(/^Reason/).fill('e2e opening float for the wallet');
    await page.getByTestId('account-adjust-confirm').click();
    await expect(dlg).toBeHidden();
    const bkash0 = await cardBalance(page, 'BKASH-01');
    const bank0 = await cardBalance(page, 'BANK-01');
    expect(bkash0).toBeGreaterThanOrEqual(20000);

    await page.goto('/ledger/expenses');
    await page.getByTestId('add-expense').click();
    await pickInDialog(page, /^Category/, 'Shop rent');
    await page.getByRole('dialog').getByLabel(/^Amount/).fill('500');
    await pickInDialog(page, /^Paid from/, /^bKash/);
    await page.getByRole('dialog').getByLabel(/^Paid to/).fill('Landlord');
    await page.getByTestId('expense-save').click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(page.getByRole('table').getByText('Landlord')).toBeVisible();

    await page.goto('/ledger/transfers');
    await page.getByTestId('add-transfer').click();
    await pickInDialog(page, /^From account/, /^bKash/);
    await pickInDialog(page, /^To account/, /^Bank/);
    await page.getByRole('dialog').getByLabel(/^Amount/).fill('5000');
    await page.getByRole('dialog').getByLabel(/^Charge/).fill('20');
    await page.getByTestId('transfer-save').click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(page.getByRole('table')).toContainText('ORB-FT-');

    await page.goto('/ledger/accounts');
    await expect(page.getByTestId('account-card-BKASH-01')).toContainText(taka(bkash0 - 500 - 5000 - 20));
    await expect(page.getByTestId('account-card-BANK-01')).toContainText(taka(bank0 + 5000));
    const bkash = await cardBalance(page, 'BKASH-01');
    const bank = await cardBalance(page, 'BANK-01');

    // The day book for a range that covers everything: each account closes on the balance its card shows.
    await page.goto('/ledger/day-book');
    await page.getByLabel(/^From/).fill('2020-01-01');
    await page.getByLabel(/^To/).fill(todayDhaka());
    const closing = async (name: string) => num(await page.getByTestId('daybook-accounts').locator('tr', { hasText: name }).locator('td').last().innerText());
    await expect.poll(() => closing('bKash')).toBe(bkash);
    expect(await closing('Bank Account')).toBe(bank);
  });

  test('dues and aging, a promise to pay, and the statement PDF', async ({ page }) => {
    await login(page, 'accountant');
    await page.goto('/ledger/dues');
    await page.locator('#main').getByLabel(/^Search/).fill(seed.customer);
    const row = page.getByTestId('dues-table').locator('tbody tr', { hasText: seed.customer });
    // 3,000 billed, 2,500 + 300 + 50 + 50 collected: 100 is left, all of it under 15 days old.
    await expect(row).toContainText(taka(100));
    await row.getByRole('button', { name: 'Promise to pay' }).click();
    await page.getByRole('dialog').getByLabel(/^Date/).fill(tomorrowDhaka());
    await page.getByRole('dialog').getByLabel(/^Note/).fill('after the weekend');
    await page.getByTestId('promise-save').click();
    await expect(row).toContainText(tomorrowDhaka());
    await expect(row).toContainText('after the weekend');

    const [download] = await Promise.all([page.waitForEvent('download'), row.getByRole('button', { name: 'Statement PDF' }).click()]);
    const pdf = await readPdf(download);
    expect(pdf.name).toBe(`Statement_${seed.customerCode}.pdf`);
    for (const needle of ['CUSTOMER STATEMENT', seed.customer, 'AMOUNT DUE FROM YOU', '100.00']) expect(pdf.text).toContain(needle);
  });

  test('a supplier is paid oldest receipt first and the payable falls', async ({ page }) => {
    await login(page, 'accountant');
    await page.goto('/ledger/receive');
    await pick(page, /^What are you doing/, 'Pay a supplier');
    await page.getByLabel('Find a supplier').fill(seed.supplier);
    await page.getByRole('button', { name: new RegExp(seed.supplier) }).click();
    await expect(page.getByTestId('allocation-panel')).toContainText(seed.grn);
    await page.getByLabel(/^Amount/).fill('1000');
    await pick(page, /^Method/, 'Bank');
    await page.getByLabel(/^Bank name/).fill('City Bank');
    await page.getByLabel(/^Transaction or cheque reference/).fill(`CHQ-${seed.stamp}`);
    await expect(page.getByTestId(`applied-${seed.grn}`)).toHaveText(taka(1000));
    await page.getByTestId('record-payment').click();
    await expect(page.getByTestId('payment-success')).toContainText('Payment made');

    await page.goto('/ledger/dues');
    await pick(page, /^Show/, 'Suppliers we owe');
    await page.locator('#main').getByLabel(/^Search/).fill(seed.supplier);
    await expect(page.getByTestId('dues-table').locator('tbody tr', { hasText: seed.supplier })).toContainText(taka(4000));
  });

  test('FR-1009: the period lock stops a payment dated inside it, and only the owner can set or lift it', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/period-lock');
    await page.getByLabel(/^Lock everything up to/).fill(todayDhaka());
    await page.getByTestId('lock-save').click();
    await expect(page.getByTestId('lock-state')).toContainText(`Locked up to ${todayDhaka()}`);

    await switchTo(page, 'accountant');
    await pickCustomer(page);
    await page.getByLabel(/^Amount/).fill('10');
    await page.getByTestId('record-payment').click();
    await expect(page.getByText(/closed period/).first()).toBeVisible();
    await page.goto('/settings/period-lock');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();

    await switchTo(page, 'super_admin');
    await page.goto('/settings/period-lock');
    await page.getByRole('button', { name: 'Remove lock' }).click();
    await expect(page.getByTestId('lock-state')).toContainText('No period is locked');
  });

  test('a cashier sees only the receive screen, and can take a payment', async ({ page }) => {
    await login(page, 'cashier');
    await page.goto('/ledger');
    await expect(page).toHaveURL(/\/ledger\/receive/);
    const tabs = page.getByRole('navigation', { name: 'Ledger' });
    await expect(tabs.getByRole('link')).toHaveCount(1);
    await expect(tabs.getByRole('link', { name: 'Receive / pay' })).toBeVisible();
    await page.goto('/ledger/accounts');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();

    await pickCustomer(page);
    await page.getByLabel(/^Amount/).fill('10');
    await page.getByTestId('record-payment').click();
    await expect(page.getByTestId('payment-success')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Recent payments' })).toHaveCount(0);
  });

  test('the customer page lists their payments and offers to receive another', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/customers');
    await page.locator('#main').getByLabel(/^Search/).fill(seed.customerCode);
    await page.getByRole('row', { name: new RegExp(seed.customerCode) }).first().click();
    await page.getByRole('tab', { name: 'Payments' }).click();
    await expect(page.getByRole('table', { name: 'Recent payments' })).toContainText('ORB-PAY-');
    await expect(page.getByTestId('receive-payment')).toBeVisible();
  });
});
