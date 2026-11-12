import { defineConfig, devices } from '@playwright/test';

// E2E tests run against the built SPA served by Laravel (php artisan serve) so cookies are same-origin.
export default defineConfig({
  testDir: '.',
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
    trace: 'off',
    viewport: { width: 1366, height: 768 },
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], launchOptions: { executablePath: process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium', args: ['--no-sandbox'] } } }],
});
