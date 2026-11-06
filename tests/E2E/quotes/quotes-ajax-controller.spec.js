/**
 * Browser coverage for application/modules/quotes/controllers/Ajax.php.
 * Mirrors tests/Feature/Quotes/QuotesAjaxControllerTest.php — the "new quote"
 * modal's $.post target. Required create fields (Mdl_Quotes::validation_rules):
 * client_id, quote_date_created, invoice_group_id.
 */

import { test, expect } from '../test.js';
import { createClient, uniq } from '../support/fixtures.js';
import { dbInsert, dbQuery } from '../support/db.js';
import { count, itemPayload, quoteRow, savePayload, seedClientRow, seedQuote, seedQuoteItem, seedQuoteSettings } from '../support/quotes.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

function payload(clientId) {
  return {
    client_id: String(clientId),
    quote_date_created: new Date().toISOString().slice(0, 10),
    invoice_group_id: '1',
    user_id: '1',
  };
}

const postCreate = async (page, form) =>
  (await page.request.post('/quotes/ajax/create', { headers: XHR, form })).json();

test.describe('Quotes AJAX — create', () => {
  test('it creates a quote', async ({ page }) => {
    /* Arrange */
    const client = await createClient(page, { client_name: uniq('AjaxQuoteClient') });

    /* Act */
    const json = await postCreate(page, payload(client.id));

    /* Assert */
    expect(json.success).toBe(1);
    expect(dbQuery(`SELECT client_id FROM ip_quotes WHERE quote_id = ${Number(json.quote_id)}`))
      .toEqual([{ client_id: client.id }]);
  });

  test('it fails to create a quote without client_id', async ({ page }) => {
    /* Arrange */
    const client = await createClient(page);

    /* Act */
    const json = await postCreate(page, { ...payload(client.id), client_id: '' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(dbQuery('SELECT quote_id FROM ip_quotes')).toEqual([]);
  });

  test('it fails to create a quote without quote_date_created', async ({ page }) => {
    /* Arrange */
    const client = await createClient(page);

    /* Act */
    const json = await postCreate(page, { ...payload(client.id), quote_date_created: '' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(dbQuery('SELECT quote_id FROM ip_quotes')).toEqual([]);
  });

  test('it fails to create a quote without invoice_group_id', async ({ page }) => {
    /* Arrange */
    const client = await createClient(page);

    /* Act */
    const json = await postCreate(page, { ...payload(client.id), invoice_group_id: '' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(dbQuery('SELECT quote_id FROM ip_quotes')).toEqual([]);
  });
});

test.describe('Quotes AJAX — guest access', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('it refuses quote creation for a guest', async ({ page }) => {
    /* Arrange + Act */
    const response = await page.request.post('/quotes/ajax/create', {
      headers: XHR,
      form: payload(1),
      maxRedirects: 0,
    });

    /* Assert */
    expect(response.status()).not.toBe(200);
    expect(dbQuery('SELECT quote_id FROM ip_quotes')).toEqual([]);
  });
});

const postAjax = async (page, path, form = {}) => (await page.request.post(path, { headers: XHR, form })).json();

test.describe('Quotes AJAX — save', () => {
  test.beforeEach(() => seedQuoteSettings());

  test('it saves a quote with items and numbers a non-draft quote', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow(), { quote_status_id: 1, quote_number: '' });
    const form = { ...savePayload(quoteId), quote_status_id: '2', quote_number: '', notes: 'Net 30, pay by wire', items: JSON.stringify([itemPayload(quoteId, 'Widget', '2', '50')]) };

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/save', form);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    const quote = quoteRow(quoteId);
    expect(Number(quote.quote_status_id)).toBe(2);
    expect(quote.notes).toBe('Net 30, pay by wire');
    expect(quote.quote_number, 'leaving draft must allocate a quote number').not.toBe('');
    expect(count('ip_quote_items', `quote_id = ${quoteId} AND item_name = 'Widget' AND item_quantity = 2`)).toBe(1);
    expect(count('ip_quote_amounts', `quote_id = ${quoteId} AND quote_item_subtotal = 100`)).toBe(1);
  });

  test('it keeps a draft quote unnumbered when saved as draft', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow(), { quote_status_id: 1, quote_number: '' });

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/save', { ...savePayload(quoteId), quote_status_id: '1', quote_number: '' });

    /* Assert */
    expect(json.success).toBe(1);
    expect(quoteRow(quoteId).quote_number).toBe('');
  });

  test('it rejects a save without an expiry date and changes nothing', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow(), { quote_status_id: 1, notes: 'original notes' });
    const form = { ...savePayload(quoteId), quote_date_expires: '', notes: 'must not be saved', items: JSON.stringify([itemPayload(quoteId, 'Ghost item', '1', '10')]) };

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/save', form);

    /* Assert */
    expect(json.success).toBe(0);
    expect(quoteRow(quoteId).notes).toBe('original notes');
    expect(count('ip_quote_items', `quote_id = ${quoteId}`)).toBe(0);
  });

  test('it rejects a save with a duplicate quote number', async ({ page }) => {
    /* Arrange */
    const clientId = seedClientRow();
    seedQuote(clientId, { quote_number: 'QUO-TAKEN' });
    const quoteId = seedQuote(clientId, { quote_number: 'QUO-MINE' });

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/save', { ...savePayload(quoteId), quote_number: 'QUO-TAKEN' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(quoteRow(quoteId).quote_number).toBe('QUO-MINE');
  });

  test('it rejects an item that has a price but no name', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());
    const form = { ...savePayload(quoteId), notes: 'SHOULD NOT PERSIST', items: JSON.stringify([itemPayload(quoteId, '', '1', '99')]) };

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/save', form);

    /* Assert: one JSON document, a reason for the item name, and no write of the header either */
    expect(json.success).toBe(0);
    expect(Object.keys(json.validation_errors ?? {})).toContain('item_name');
    expect(count('ip_quote_items', `quote_id = ${quoteId}`)).toBe(0);
    expect(quoteRow(quoteId).notes).not.toBe('SHOULD NOT PERSIST');
  });

  test('it applies only the percent discount when both discounts are posted', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());

    /* Act */
    await postAjax(page, '/quotes/ajax/save', { ...savePayload(quoteId), quote_discount_percent: '10', quote_discount_amount: '25' });

    /* Assert: the two global discounts are mutually exclusive; percent wins */
    expect(Number(quoteRow(quoteId).quote_discount_percent)).toBe(10);
    expect(Number(quoteRow(quoteId).quote_discount_amount)).toBe(0);
  });
});

test.describe('Quotes AJAX — items', () => {
  test('it deletes a quote item', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());
    const itemId = seedQuoteItem(quoteId, 'Disposable');
    const keepId = seedQuoteItem(quoteId, 'Keeper');

    /* Act */
    const json = await postAjax(page, `/quotes/ajax/delete_item/${quoteId}`, { item_id: String(itemId) });

    /* Assert */
    expect(json.success).toBe(1);
    expect(count('ip_quote_items', `item_id = ${itemId}`)).toBe(0);
    expect(count('ip_quote_items', `item_id = ${keepId}`)).toBe(1);
  });

  test('it does not delete an item when the quote does not exist', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());
    const itemId = seedQuoteItem(quoteId, 'Protected');

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/delete_item/999999', { item_id: String(itemId) });

    /* Assert */
    expect(json.success).toBe(0);
    expect(count('ip_quote_items', `item_id = ${itemId}`)).toBe(1);
  });

  test("it refuses to delete an item through another quote's url", async ({ page }) => {
    /* Arrange: the item belongs to quote B, the request is addressed to quote A */
    const clientId = seedClientRow();
    const quoteA = seedQuote(clientId);
    const quoteB = seedQuote(clientId);
    const itemOfB = seedQuoteItem(quoteB, 'Belongs to B');

    /* Act */
    const json = await postAjax(page, `/quotes/ajax/delete_item/${quoteA}`, { item_id: String(itemOfB) });

    /* Assert */
    expect(json.success).toBe(0);
    expect(count('ip_quote_items', `item_id = ${itemOfB} AND quote_id = ${quoteB}`)).toBe(1);
  });

  test('it returns a quote item by id', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());
    seedQuoteItem(quoteId, 'Other item');
    const itemId = seedQuoteItem(quoteId, 'Lookup me');

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/get_item', { item_id: String(itemId) });

    /* Assert */
    expect(json.item_name).toBe('Lookup me');
    expect(String(json.item_id)).toBe(String(itemId));
  });
});

test.describe('Quotes AJAX — tax rates', () => {
  test('it saves a quote tax rate', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());
    const taxRateId = dbInsert('ip_tax_rates', { tax_rate_name: 'VAT 21', tax_rate_percent: '21.00' });

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/save_quote_tax_rate', { quote_id: String(quoteId), tax_rate_id: String(taxRateId), include_item_tax: '0' });

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(count('ip_quote_tax_rates', `quote_id = ${quoteId} AND tax_rate_id = ${taxRateId}`)).toBe(1);
  });

  test('it rejects a quote tax rate without a tax rate', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/save_quote_tax_rate', { quote_id: String(quoteId), include_item_tax: '0' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(count('ip_quote_tax_rates', `quote_id = ${quoteId}`)).toBe(0);
  });
});

test.describe('Quotes AJAX — copy and reassign', () => {
  test('it copies a quote with its items and leaves the original intact', async ({ page }) => {
    /* Arrange */
    const sourceClient = seedClientRow({ client_name: 'Source Client' });
    const targetClient = seedClientRow({ client_name: 'Target Client' });
    const sourceId = seedQuote(sourceClient, { quote_number: 'QUO-SRC' });
    seedQuoteItem(sourceId, 'Copied item');

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/copy_quote', { ...payload(targetClient), quote_id: String(sourceId) });

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    const copyId = Number(json.quote_id);
    expect(copyId).not.toBe(sourceId);
    expect(count('ip_quotes', `quote_id = ${copyId} AND client_id = ${targetClient}`)).toBe(1);
    expect(count('ip_quote_items', `quote_id = ${copyId} AND item_name = 'Copied item'`)).toBe(1);
    expect(count('ip_quotes', `quote_id = ${sourceId} AND client_id = ${sourceClient} AND quote_number = 'QUO-SRC'`)).toBe(1);
    expect(count('ip_quote_items', `quote_id = ${sourceId} AND item_name = 'Copied item'`)).toBe(1);
  });

  test('it fails to copy a quote without a client', async ({ page }) => {
    /* Arrange */
    const sourceId = seedQuote(seedClientRow());

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/copy_quote', { ...payload(0), client_id: '', quote_id: String(sourceId) });

    /* Assert */
    expect(json.success).toBe(0);
    expect(count('ip_quotes')).toBe(1);
  });

  test("it changes the quote's user", async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());
    const userId = dbInsert('ip_users', {
      user_type: 1, user_name: 'New Owner', user_email: 'new-owner@test.local', user_password: 'x', user_psalt: 'abcd1234', user_language: 'system', user_active: 1,
      user_date_created: '2026-10-04 12:00:00', user_date_modified: '2026-10-04 12:00:00',
    });

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/change_user', { quote_id: String(quoteId), user_id: String(userId) });

    /* Assert */
    expect(json.success).toBe(1);
    expect(Number(quoteRow(quoteId).user_id)).toBe(userId);
  });

  test("it refuses to change the quote's user to an unknown user", async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/change_user', { quote_id: String(quoteId), user_id: '999999' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(Number(quoteRow(quoteId).user_id)).toBe(1);
  });

  test("it changes the quote's client", async ({ page }) => {
    /* Arrange */
    const oldClient = seedClientRow({ client_name: 'Old Client' });
    const newClient = seedClientRow({ client_name: 'New Client' });
    const quoteId = seedQuote(oldClient);

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/change_client', { quote_id: String(quoteId), client_id: String(newClient) });

    /* Assert */
    expect(json.success).toBe(1);
    expect(Number(quoteRow(quoteId).client_id)).toBe(newClient);
  });

  test("it refuses to change the quote's client to an unknown client", async ({ page }) => {
    /* Arrange */
    const clientId = seedClientRow();
    const quoteId = seedQuote(clientId);

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/change_client', { quote_id: String(quoteId), client_id: '999999' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(Number(quoteRow(quoteId).client_id)).toBe(clientId);
  });
});

test.describe('Quotes AJAX — quote to invoice', () => {
  const conversionForm = (quoteId, clientId) => ({
    quote_id: String(quoteId),
    client_id: String(clientId),
    invoice_date_created: new Date().toISOString().slice(0, 10),
    invoice_time_created: new Date().toTimeString().slice(0, 8),
    invoice_group_id: '1',
    user_id: '1',
  });

  test('it converts a quote into an invoice carrying items and discount', async ({ page }) => {
    /* Arrange */
    seedQuoteSettings();
    const clientId = seedClientRow();
    const quoteId = seedQuote(clientId, { quote_discount_percent: '10.00' });
    seedQuoteItem(quoteId, 'Consulting', '3', '100');

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/quote_to_invoice', conversionForm(quoteId, clientId));

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    const invoiceId = Number(json.invoice_id);
    expect(count('ip_invoices', `invoice_id = ${invoiceId} AND client_id = ${clientId} AND invoice_discount_percent = 10`)).toBe(1);
    expect(count('ip_invoice_items', `invoice_id = ${invoiceId} AND item_name = 'Consulting' AND item_quantity = 3`)).toBe(1);
    expect(count('ip_quotes', `quote_id = ${quoteId} AND invoice_id = ${invoiceId}`)).toBe(1);
    expect(count('ip_invoice_amounts', `invoice_id = ${invoiceId} AND invoice_item_subtotal = 300`)).toBe(1);
  });

  test('it creates no invoice when the conversion form is incomplete', async ({ page }) => {
    /* Arrange */
    const clientId = seedClientRow();
    const quoteId = seedQuote(clientId);
    seedQuoteItem(quoteId, 'Untouched');
    const { invoice_group_id: _omitted, ...incomplete } = conversionForm(quoteId, clientId);

    /* Act */
    const json = await postAjax(page, '/quotes/ajax/quote_to_invoice', incomplete);

    /* Assert */
    expect(json.success).toBe(0);
    expect(count('ip_invoices')).toBe(0);
    expect(count('ip_quotes', `quote_id = ${quoteId} AND invoice_id = 0`)).toBe(1);
  });

  test('it reports an unknown quote in the conversion modal', async ({ page }) => {
    /* Act */
    const json = await postAjax(page, '/quotes/ajax/modal_quote_to_invoice/999999');

    /* Assert */
    expect(json.success).toBe(0);
  });

  test('it renders the conversion modal for an existing quote', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow(), { quote_number: 'QUO-MODAL-1' });

    /* Act */
    const response = await page.request.post(`/quotes/ajax/modal_quote_to_invoice/${quoteId}`, { headers: XHR });
    const html = await response.text();

    /* Assert */
    expect(response.status()).toBe(200);
    expect(html).toContain('quote_id');
    expect(html).not.toMatch(/A PHP Error was encountered|Fatal error|<b>(Warning|Notice|Deprecated)<\/b>/);
  });
});
