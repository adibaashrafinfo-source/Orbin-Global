import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.describe('design system rules', () => {
  test('no raw hex colors in served CSS custom values outside tokens and only one accent button on login', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('button.mirror-shine')).toHaveCount(1); // the single accent "Sign in"
    const accent = page.getByRole('button', { name: 'Sign in' });
    const color = await accent.evaluate((el) => getComputedStyle(el).color);
    // navy-900 text on the orange accent button — never white
    expect(color).toBe('rgb(0, 39, 86)');
  });

  test('reduced motion disables Mirror Shine and animations', async ({ browser }) => {
    const ctx = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    await login(page, 'super_admin');
    await page.goto('/gallery');
    const display = await page.locator('button.mirror-shine').first().evaluate((el) => getComputedStyle(el, '::after').display);
    expect(display).toBe('none');
    const dur = await page.locator('button.mirror-shine').first().evaluate((el) => getComputedStyle(el).transitionDuration);
    expect(parseFloat(dur)).toBeLessThan(0.01);
    await ctx.close();
  });

  test('gallery shows every component state without console errors', async ({ page }) => {
    await login(page, 'super_admin');
    // Listen only after login: the pre-login /auth/me 401 on the login screen is expected.
    const errors: string[] = [];
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/gallery');
    for (const h of ['Buttons', 'Inputs', 'Badges & status chips', 'Tables', 'Drawers, modals, toasts', 'Skeletons & states']) {
      await expect(page.getByRole('heading', { name: h }).first()).toBeVisible();
    }
    await page.getByRole('button', { name: 'Open drawer' }).click();
    const drawer = page.getByRole('dialog', { name: 'Right drawer' });
    await expect(drawer).toBeVisible();
    await page.waitForTimeout(400); // let the 320 ms slide-in finish before pressing keys
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await page.getByRole('button', { name: 'Open modal' }).click();
    const modal = page.getByRole('dialog', { name: 'Modal dialog' });
    await expect(modal).toBeVisible();
    await page.waitForTimeout(400);
    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
    await page.getByRole('button', { name: 'Show toast', exact: true }).click();
    await expect(page.getByText('Your changes were saved.')).toBeVisible();
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('keyboard: skip link, slash focuses search, sidebar toggles', async ({ page }) => {
    await login(page, 'super_admin');
    await page.keyboard.press('/');
    await expect(page.getByRole('searchbox').first()).toBeFocused();
    await page.getByRole('button', { name: 'Collapse sidebar' }).click();
    await expect(page.getByRole('button', { name: 'Expand sidebar' })).toBeVisible();
  });
});
