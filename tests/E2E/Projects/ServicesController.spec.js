/**
 * Browser coverage for application/modules/services/controllers/Services.php.
 * Mirrors tests/Feature/Projects/ServicesControllerTest.php.
 * Required field: service_name (unique).
 */

import { test, expect } from '../test.js';
import { createClient, createService, uniq } from '../support/fixtures.js';
import { dbInsert, dbQuery } from '../support/db.js';
import { expectBlockedByRequired, expectValidationError } from '../support/forms.js';

test.describe('Services — list', () => {
  test('it lists every service', async ({ page }) => {
    const a = await createService(page, { service_name: uniq('ServiceOne') });
    const b = await createService(page, { service_name: uniq('ServiceTwo') });

    await page.goto('/services');

    await expect(page.locator('#content')).toContainText(a.name);
    await expect(page.locator('#content')).toContainText(b.name);
  });
});

test.describe('Services — create', () => {
  test('it creates a service', async ({ page }) => {
    const name = uniq('NewService');

    await page.goto('/services/form');
    await page.fill('#service_name', name);
    await Promise.all([page.waitForURL(/\/services(\/index)?$/), page.click('#btn-submit')]);

    await expect(page.locator('#content')).toContainText(name);
  });

  test('it fails to create a service without a name', async ({ page }) => {
    await page.goto('/services/form');
    await expectBlockedByRequired(page, '#service_name');
  });

  test('it fails to create a service whose name is already taken', async ({ page }) => {
    const name = uniq('TakenService');
    await createService(page, { service_name: name });

    await page.goto('/services/form');
    await page.fill('#service_name', name);
    await Promise.all([page.waitForLoadState('load'), page.click('#btn-submit')]);

    await expect(page).toHaveURL(/\/services\/form/);
    await expectValidationError(page);
  });
});

test.describe('Services — update', () => {
  test('it updates a service', async ({ page }) => {
    const service = await createService(page, { service_name: uniq('OriginalService') });
    const renamed = uniq('RenamedService');

    await page.goto(`/services/form/${service.id}`);
    await page.fill('#service_name', renamed);
    await Promise.all([page.waitForURL(/\/services(\/index)?$/), page.click('#btn-submit')]);

    await expect(page.locator('#content')).toContainText(renamed);
  });

  test('it fails to update a service without a name', async ({ page }) => {
    const service = await createService(page, { service_name: uniq('KeepThisService') });

    await page.goto(`/services/form/${service.id}`);
    await page.fill('#service_name', '');
    await expectBlockedByRequired(page, '#service_name');
  });
});

test.describe('Services — client pinning', () => {
  test('it creates a service and pins it to a client', async ({ page }) => {
    const client = await createClient(page);
    const name = uniq('PinnedService');

    const response = await page.request.post(`/services/form_client/${client.id}`, {
      form: { service_name: name, client_id: String(client.id), btn_submit: '1' },
      maxRedirects: 0,
    });

    expect(response.status()).toBe(303);
    expect(response.headers().location).toContain(`clients/form/${client.id}`);
    const [svc] = dbQuery(`SELECT service_id FROM ip_services WHERE service_name = ${sqlStr(name)}`);
    expect(svc).toBeTruthy();
    expect(dbQuery(`SELECT client_id FROM ip_client_services WHERE service_id = ${svc.service_id}`))
      .toEqual([{ client_id: client.id }]);
  });

  test('it 404s when pinning a service to a client that does not exist', async ({ page }) => {
    const response = await page.request.post('/services/form_client/999999', {
      form: { service_name: uniq('GhostService'), btn_submit: '1' },
      maxRedirects: 0,
    });

    expect(response.status()).toBe(404);
  });
});

test.describe('Services — invoice tagging', () => {
  // Exercised via the Filter AJAX endpoint against a tagged invoice; belongs
  // with the Core FilterAjaxController spec where the invoice fixture lives.
  test('it resolves a tagged invoices service name in the filtered invoice table', async () => {
    test.fixme(true, 'covered by tests/E2E/Core/FilterAjaxController.spec.js (needs an invoice fixture)');
  });
});

test.describe('Services — delete', () => {
  test('it deletes a service and its client links', async ({ page }) => {
    const client = await createClient(page);
    const service = await createService(page, { service_name: uniq('LinkedService') });
    dbInsert('ip_client_services', { client_id: client.id, service_id: service.id });

    await page.goto('/services');
    const row = page.locator('tr', { hasText: service.name });
    await row.locator('.dropdown-toggle').click();
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForLoadState('load'), row.locator('button.dropdown-button').click()]);

    expect(dbQuery(`SELECT service_id FROM ip_services WHERE service_id = ${service.id}`)).toEqual([]);
    expect(dbQuery(`SELECT service_id FROM ip_client_services WHERE service_id = ${service.id}`)).toEqual([]);
  });

  test('it does not delete a service on a plain get request', async ({ page }) => {
    const service = await createService(page, { service_name: uniq('GetSafeService') });

    const response = await page.request.get(`/services/delete/${service.id}`, { maxRedirects: 0 });

    expect(response.status()).not.toBe(200);
    expect(dbQuery(`SELECT service_id FROM ip_services WHERE service_id = ${service.id}`)).toHaveLength(1);
  });
});

test.describe('Services — guest access', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('it redirects a guest to login and leaks no service', async ({ page }) => {
    const response = await page.goto('/services');

    await expect(page).toHaveURL(/\/sessions\/login/);
    expect(await response.text()).not.toContain('Secret Service');
  });
});

function sqlStr(value) {
  return `'${value.replace(/'/g, "''")}'`;
}
