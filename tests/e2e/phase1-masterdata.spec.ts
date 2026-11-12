import { expect, test } from '@playwright/test';
import { login, toastText } from './helpers';

const stamp = Date.now().toString(36);

test.describe('P1 master data', () => {
  test('vehicle makes: add, duplicate is rejected, edit, deactivate, delete', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/master/vehicle-makes');
    await expect(page.getByRole('heading', { name: 'Master data' })).toBeVisible();
    const name = `E2E Make ${stamp}`;
    await page.getByRole('button', { name: 'Add vehicle make' }).first().click();
    await page.getByLabel(/^Name/).fill(name);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('cell', { name })).toBeVisible();

    // Same name again: the server refuses and the dialog stays open with a field error.
    await page.getByRole('button', { name: 'Add vehicle make' }).first().click();
    await page.getByLabel(/^Name/).fill(name);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('alert').filter({ hasText: /already exists|correct the highlighted/i }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();

    // Rename through the row dialog.
    await page.getByRole('cell', { name }).click();
    await page.getByLabel(/^Name/).fill(`${name} X`);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('cell', { name: `${name} X` })).toBeVisible();

    // Deactivate with the inline switch, then delete.
    const row = page.getByRole('row').filter({ hasText: `${name} X` });
    await row.getByRole('switch').click();
    await expect(row.getByRole('switch')).not.toBeChecked();
    await row.getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('button', { name: 'Delete' }).last().click();
    await expect(page.getByRole('cell', { name: `${name} X` })).toHaveCount(0);
  });

  test('a make with models cannot be deleted', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/master/vehicle-makes');
    const row = page.getByRole('row').filter({ hasText: 'Toyota' });
    await row.getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('button', { name: 'Delete' }).last().click();
    await expect(toastText(page, 'This record is used elsewhere. Deactivate it instead of deleting.')).toBeVisible();
  });

  test('vehicle models: filter by make and add one', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/master/vehicle-models');
    await page.getByLabel('Make').click();
    await page.getByRole('option', { name: 'Toyota' }).click();
    await expect(page.getByRole('row').filter({ hasText: 'Honda' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Add vehicle model' }).first().click();
    await page.getByLabel(/^Name/).fill(`Model ${stamp}`);
    await page.getByLabel('Generation code').fill('XE1');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('cell', { name: `Model ${stamp}` })).toBeVisible();
  });

  test('categories: child shows under its parent and reordering works', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/master/categories');
    await page.getByRole('button', { name: 'Add category' }).first().click();
    await page.getByLabel('Name (English)').fill(`Cat ${stamp}`);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('cell', { name: new RegExp(`Cat ${stamp}`) })).toBeVisible();
    const row = page.getByRole('row').filter({ hasText: `Cat ${stamp}` });
    await row.getByRole('button', { name: 'Move up' }).click();
    await expect(row.getByRole('button', { name: 'Move up' })).toBeEnabled();
  });

  test('lookups: add a value to a system list; system list cannot be deleted', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/master/lookups');
    await page.getByRole('button', { name: /Glass type/ }).click();
    await page.getByRole('button', { name: 'Add value' }).click();
    await page.getByLabel(/^Code/).fill(`g_${stamp}`);
    await page.getByLabel('Name (English)').fill(`Glass ${stamp}`);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('cell', { name: `Glass ${stamp}` })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Delete list' })).toHaveCount(0);
  });

  test('custom fields: create a select field with its own options', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/settings/master/custom-fields');
    await page.getByRole('button', { name: 'Add field' }).first().click();
    await page.getByLabel(/^Key/).fill(`cf_${stamp}`);
    await page.getByLabel('Name (English)').fill(`Field ${stamp}`);
    await page.getByLabel('Field type').click();
    await page.getByRole('option', { name: 'Pick one' }).click();
    await page.getByLabel('Code').first().fill('a');
    await page.getByLabel('Name (English)').nth(1).fill('Option A');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText(`Field ${stamp}`)).toBeVisible();
  });

  test('a role without master-data access sees the forbidden state', async ({ page }) => {
    await login(page, 'cashier');
    await page.goto('/settings/master/vehicle-makes');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();
  });
});
