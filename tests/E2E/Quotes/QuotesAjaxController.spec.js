/**
 * Browser coverage for application/modules/quotes/controllers/Ajax.php.
 * Mirrors tests/Feature/Quotes/QuotesAjaxControllerTest.php — the "new quote"
 * modal's $.post target. Required create fields (Mdl_Quotes::validation_rules):
 * client_id, quote_date_created, invoice_group_id.
 */

import { test, expect } from '../test.js';
import { createClient, uniq } from '../support/fixtures.js';
import { dbQuery } from '../support/db.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

function payload(clientId) {
  return {
    client_id: String(clientId),
    quote_date_created: new Date().toISOString().slice(0, 10),
    invoice_group_id: '1',
    user_id: '1',
  };
}

async function postCreate(page, form) {
  const response = await page.request.post('/quotes/ajax/create', { headers: XHR, form });

  return response.json();
}

test.describe('Quotes AJAX — create', () => {
  test('it creates a quote', async ({ page }) => {
    const client = await createClient(page, { client_name: uniq('AjaxQuoteClient') });

    const json = await postCreate(page, payload(client.id));

    expect(json.success).toBe(1);
    const rows = dbQuery(`SELECT client_id FROM ip_quotes WHERE quote_id = ${Number(json.quote_id)}`);
    expect(rows).toEqual([{ client_id: client.id }]);
  });

  test('it fails to create a quote without client_id', async ({ page }) => {
    const client = await createClient(page);

    const json = await postCreate(page, { ...payload(client.id), client_id: '' });

    expect(json.success).toBe(0);
    expect(dbQuery('SELECT quote_id FROM ip_quotes')).toEqual([]);
  });

  test('it fails to create a quote without quote_date_created', async ({ page }) => {
    const client = await createClient(page);

    const json = await postCreate(page, { ...payload(client.id), quote_date_created: '' });

    expect(json.success).toBe(0);
    expect(dbQuery('SELECT quote_id FROM ip_quotes')).toEqual([]);
  });

  test('it fails to create a quote without invoice_group_id', async ({ page }) => {
    const client = await createClient(page);

    const json = await postCreate(page, { ...payload(client.id), invoice_group_id: '' });

    expect(json.success).toBe(0);
    expect(dbQuery('SELECT quote_id FROM ip_quotes')).toEqual([]);
  });
});

test.describe('Quotes AJAX — guest access', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('it refuses quote creation for a guest', async ({ page }) => {
    const response = await page.request.post('/quotes/ajax/create', {
      headers: XHR,
      form: payload(1),
      maxRedirects: 0,
    });

    expect(response.status()).not.toBe(200);
    expect(dbQuery('SELECT quote_id FROM ip_quotes')).toEqual([]);
  });
});
