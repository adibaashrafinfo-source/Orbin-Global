import { expect, test } from '@playwright/test';
import { login } from './helpers';

// FR-706: the sale channel, salesperson and invoice note live in the cart's "Sale details" dialog and show on the cart bar.
test('the cashier sets the channel, salesperson and note, and the cart bar shows them', async ({ page }) => {
  test.setTimeout(90_000);
  await login(page, 'cashier');
  await page.goto('/pos');
  const gate = page.getByRole('button', { name: /Open shift|শিফট খুলুন/ });
  await gate.or(page.getByTestId('pos-terminal')).first().waitFor();
  if (await gate.isVisible()) await gate.click();
  await expect(page.getByTestId('pos-terminal')).toBeVisible();

  await page.getByRole('button', { name: 'Sale details' }).click();
  const dialog = page.getByRole('dialog', { name: 'Sale details' });
  await expect(dialog).toBeVisible();
  await dialog.getByLabel('Sale channel').click();
  await page.getByRole('option', { name: 'Phone', exact: true }).click();
  await dialog.getByLabel('Salesperson').click();
  await expect(page.getByRole('option', { name: /Me \(the person at the till\)/ })).toBeVisible();
  await page.getByRole('option', { name: /Me \(the person at the till\)/ }).click();
  await dialog.getByLabel('Invoice note').fill('Call before delivery');
  await dialog.getByRole('button', { name: 'Done' }).click();
  await expect(dialog).toBeHidden();

  const bar = page.getByRole('button', { name: 'Sale details' }).first();
  await expect(bar).toContainText('Phone');
  await expect(bar).toContainText('Me (the person at the till)');
});
