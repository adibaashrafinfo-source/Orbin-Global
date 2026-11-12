import { test } from '@playwright/test';
import { login } from './helpers';

const SIZES = [
  { name: '1366x768', width: 1366, height: 768 },
  { name: '1920x1080', width: 1920, height: 1080 },
] as const;
const SCREENS = [
  ['login', '/login', false],
  ['dashboard', '/dashboard', true],
  ['company', '/settings/company', true],
  ['branches', '/settings/branches', true],
  ['users', '/settings/users', true],
  ['roles', '/settings/roles', true],
  ['numbering', '/settings/numbering', true],
  ['pos-rules', '/settings/pos', true],
  ['audit-log', '/settings/audit-log', true],
  ['health', '/admin/health', true],
  ['gallery', '/gallery', true],
] as const;

// Not part of the pass/fail gate: produces the screenshots referenced by docs/phase-0-summary.md.
test.describe('screenshots', () => {
  test.skip(!process.env.CAPTURE, 'set CAPTURE=1 to capture screenshots');
  for (const size of SIZES) {
    test(`capture ${size.name}`, async ({ browser }) => {
      const ctx = await browser.newContext({ viewport: { width: size.width, height: size.height }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      for (const [name, path, auth] of SCREENS) {
        if (auth && !page.url().includes('/dashboard') && page.url() === 'about:blank') await login(page, 'super_admin');
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(700);
        await page.screenshot({ path: `../../docs/screenshots/${name}-${size.name}.png`, fullPage: false });
        if (name === 'login') await login(page, 'super_admin');
      }
      await ctx.close();
    });
  }
});
