import { defineConfig, devices } from '@playwright/test';
import path from 'path';
import { E2E_BASE_URL, E2E_CSRF_BASE_URL } from './tests/E2E/config.js';

// Start our own PHP built-in server only for local runs that did not point
// E2E_BASE_URL at an already-running instance. CI starts the server itself
// (see .github/workflows/e2e-tests.yml) and sets E2E_BASE_URL.
const manageServer = !process.env.CI && !process.env.E2E_BASE_URL;

// Two instances share one database: the default one runs with CSRF protection off (the forms are driven
// through the UI), the second with it on, for the specs that prove a request is refused without a token
// or that the browser sends one (*.csrf.spec.js). Both are started by CI itself via E2E_BASE_URL.
// COOKIE_SECURE=false there: CodeIgniter will not set the CSRF cookie over plain HTTP while cookie_secure is on.
const phpServer = (baseUrl, extraEnv = '') =>
  `${extraEnv}PHP_CLI_SERVER_WORKERS=4 IP_URL=${baseUrl} REMOVE_INDEXPHP=true SETUP_COMPLETED=true DB_HOSTNAME=\${DB_HOSTNAME:-127.0.0.1} ` +
  `php -d variables_order=EGPCS -S localhost:${new URL(baseUrl).port} -t . bootstrap/test-server.php`;

// Two passes (tests/E2E/run-with-diagnostic-rerun.mjs). The first records nothing, which keeps it fast. Only the tests that
// failed in it are run again with E2E_DIAGNOSTICS=1: a trace, a video and a screenshot for each, in separate folders.
const diagnostics = !!process.env.E2E_DIAGNOSTICS;

export default defineConfig({
  testDir: './tests/E2E',
  testMatch: '**/*.spec.js',
  // Serial by design: one shared app instance (PHP's built-in server handles
  // one request at a time) backed by one database that every test truncates and
  // reseeds before it runs (tests/E2E/test.js). Parallel workers would race on
  // both. Keep this in step with the PHPUnit Feature suite's per-test reset.
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  // No in-place retries and no early stop: every test runs once, then the failures are re-run with diagnostics, which
  // also tells a flaky test (passes on the re-run) from a broken one.
  retries: 0,
  workers: 1,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
    ...(process.env.CI ? [['github']] : []),
    ['./tests/E2E/error-summary-reporter.js'],
  ],
  globalSetup: path.resolve('./tests/E2E/global-setup.js'),
  use: {
    baseURL: E2E_BASE_URL,
    trace: diagnostics ? 'on' : 'off',
    screenshot: 'only-on-failure',
    video: diagnostics ? 'on' : 'off',
    storageState: 'tests/E2E/.auth/admin.json',
  },
  projects: [
    {
      name: 'chromium',
      testIgnore: '**/*.csrf.spec.js',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'chromium-csrf',
      testMatch: '**/*.csrf.spec.js',
      use: { ...devices['Desktop Chrome'], baseURL: E2E_CSRF_BASE_URL },
    },
  ],
  webServer: manageServer
    ? [
        // `-d variables_order=EGPCS`: this build's php.ini omits `E`, so without
        // it exported vars never reach $_ENV and InvoicePlane's env() (which
        // reads $_ENV only) can't see DB_HOSTNAME=127.0.0.1 — the app then tries
        // the Docker-only `mariadb` host from ipconfig.php and fails to boot.
        { command: phpServer(E2E_BASE_URL, 'CSRF_PROTECTION=false '), url: `${E2E_BASE_URL}/sessions/login`, reuseExistingServer: true, timeout: 120 * 1000 },
        { command: phpServer(E2E_CSRF_BASE_URL, 'CSRF_PROTECTION=true COOKIE_SECURE=false '), url: `${E2E_CSRF_BASE_URL}/sessions/login`, reuseExistingServer: true, timeout: 120 * 1000 },
      ]
    : undefined,
});
