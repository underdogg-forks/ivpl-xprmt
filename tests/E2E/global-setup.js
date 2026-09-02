import { chromium } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { E2E_BASE_URL, E2E_EMAIL, E2E_PASSWORD, LOGIN_PATH } from './config.js';

/**
 * Log in once for the whole run and persist the session, so every spec
 * starts authenticated via playwright.config.js's `storageState`.
 */
const authFile = path.resolve('tests/E2E/.auth/admin.json');

export default async function globalSetup() {
  fs.mkdirSync(path.dirname(authFile), { recursive: true });

  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL: E2E_BASE_URL });
  const page = await context.newPage();

  await page.goto(LOGIN_PATH);
  await page.fill('input[name="email"]', E2E_EMAIL);
  await page.fill('input[name="password"]', E2E_PASSWORD);

  // A generous timeout: this runs once for the whole suite and the dev PHP
  // server can be slow on a cold start, so it is cheap insurance rather than
  // a mask for a real bug.
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes(LOGIN_PATH), { timeout: 60000 }),
    page.click('form button[type="submit"]'),
  ]);

  await context.storageState({ path: authFile });
  await browser.close();
}
