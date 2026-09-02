import { defineConfig, devices } from '@playwright/test';
import path from 'path';
import { E2E_BASE_URL } from './tests/E2E/config.js';

// Start our own PHP built-in server only for local runs that did not point
// E2E_BASE_URL at an already-running instance. CI starts the server itself
// (see .github/workflows/e2e-tests.yml) and sets E2E_BASE_URL.
const manageServer = !process.env.CI && !process.env.E2E_BASE_URL;

export default defineConfig({
  testDir: './tests/E2E',
  testMatch: '**/*.spec.js',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
    ...(process.env.CI ? [['github']] : []),
    ['./tests/E2E/error-summary-reporter.js'],
  ],
  globalSetup: path.resolve('./tests/E2E/global-setup.js'),
  use: {
    baseURL: E2E_BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    storageState: 'tests/E2E/.auth/admin.json',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: manageServer
    ? {
        command: 'php -S localhost:8000 -t . tests/E2E/router.php',
        url: E2E_BASE_URL,
        reuseExistingServer: true,
        timeout: 120 * 1000,
      }
    : undefined,
});
