import { expect, type Page } from '@playwright/test';

export const PASSWORD = 'Orbin@2026';

export const USERS = {
  super_admin: 'admin@orbin.local',
  manager: 'manager@orbin.local',
  cashier: 'cashier@orbin.local',
  storekeeper: 'store@orbin.local',
  accountant: 'accounts@orbin.local',
} as const;

export type RoleKey = keyof typeof USERS;

export async function login(page: Page, role: RoleKey): Promise<void> {
  await page.goto('/login');
  await page.getByLabel(/^Email address/).fill(USERS[role]);
  await page.getByLabel(/^Password/).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  // Guests bounced from a protected page return to it after login; otherwise the dashboard opens.
  await expect(page).not.toHaveURL(/\/login/);
  await expect(page.getByRole('navigation').first()).toBeVisible();
}

/** Toast text lives in the toast and in Radix's screen-reader live region; match the visible toast only. */
export function toastText(page: Page, text: string) {
  return page.getByText(text, { exact: true }).first();
}

/** Resets state the tests mutate (via artisan) so the suite is repeatable. */
export const RESET_HINT = 'php artisan migrate:fresh --seed --force';
