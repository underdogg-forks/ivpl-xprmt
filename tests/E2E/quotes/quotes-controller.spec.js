/**
 * Browser coverage for application/modules/quotes/controllers/Quotes.php.
 * Mirrors tests/Feature/Quotes/QuotesControllerTest.php.
 */

import { test, expect } from '../test.js';
import { createClient, createQuote, createTaxRate, uniq } from '../support/fixtures.js';
import { dbInsert, dbQuery } from '../support/db.js';
import { postForm } from '../support/http.js';
import { expectSavedFlash } from '../support/forms.js';
import { count, seedClientRow, seedQuote, seedQuoteSettings } from '../support/quotes.js';

test.describe('Quotes — list', () => {
  test('it lists every quote', async ({ page }) => {
    /* Arrange */
    const client = await createClient(page, { client_name: uniq('QuoteListClient') });
    const a = await createQuote(page, { client_id: client.id });
    const b = await createQuote(page, { client_id: client.id });

    /* Act */
    await page.goto('/quotes/status/all');

    /* Assert: one delete form per quote row — an unambiguous per-row marker */
    await expect(page.locator(`form[action*="quotes/delete/${a.id}"]`)).toHaveCount(1);
    await expect(page.locator(`form[action*="quotes/delete/${b.id}"]`)).toHaveCount(1);
    expect(a.number).not.toBe('');
    await expect(page.locator(`tr:has(form[action*="quotes/delete/${a.id}"])`)).toContainText(a.number);
  });
});

test.describe('Quotes — view', () => {
  test('it shows a single quote', async ({ page }) => {
    /* Arrange */
    const client = await createClient(page, { client_name: uniq('QuoteViewClient') });
    const quote = await createQuote(page, { client_id: client.id });

    /* Act */
    await page.goto(`/quotes/view/${quote.id}`);

    /* Assert */
    await expect(page.locator('#quote_number')).toHaveValue(quote.number);
    await expect(page.locator('#content')).toContainText(client.name);
  });
});

  // CSRF token tests (valid token, missing token) are covered by Feature tests
  // and cannot run in E2E because CSRF_PROTECTION=false in the test server.
test.describe('Quotes — delete', () => {
  test('it deletes a quote', async ({ page }) => {
    /* Arrange */
    const client = await createClient(page);
    const doomed = await createQuote(page, { client_id: client.id });
    const kept = await createQuote(page, { client_id: client.id });

    /* Act */
    await page.goto('/quotes/status/all');
    const row = page.locator('tr', { has: page.locator(`form[action*="quotes/delete/${doomed.id}"]`) });
    await row.locator('.dropdown-toggle').click();
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForLoadState('load'), row.locator('button.dropdown-button').click()]);

    /* Assert: Flash message confirms deletion */
    await expectSavedFlash(page);

    /* Assert: UI shows deletion */
    await page.goto('/quotes/status/all');
    await expect(page.locator(`form[action*="quotes/delete/${doomed.id}"]`)).toHaveCount(0);
    await expect(page.locator(`form[action*="quotes/delete/${kept.id}"]`)).toHaveCount(1);

    /* Assert: Database confirms hard delete */
    expect(dbQuery(`SELECT quote_id FROM ip_quotes WHERE quote_id = ${doomed.id}`)).toEqual([]);

    /* Assert: Other quote unaffected */
    expect(dbQuery(`SELECT quote_id FROM ip_quotes WHERE quote_id = ${kept.id}`)).toHaveLength(1);
  });
});

test.describe('Quotes — tax rates', () => {
  test('it removes a tax rate from a quote', async ({ page }) => {
    /* Arrange */
    const quote = await createQuote(page);
    const rate = await createTaxRate(page);
    const removeId = dbInsert('ip_quote_tax_rates', {
      quote_id: quote.id, tax_rate_id: rate.id, include_item_tax: 0, quote_tax_rate_amount: '5.00',
    });
    const keepId = dbInsert('ip_quote_tax_rates', {
      quote_id: quote.id, tax_rate_id: rate.id, include_item_tax: 0, quote_tax_rate_amount: '7.00',
    });

    /* Act */
    const response = await postForm(page, `/quotes/delete_quote_tax/${quote.id}/${removeId}`, {});

    /* Assert */
    expect([301, 302, 303]).toContain(response.status());
    expect(dbQuery(`SELECT quote_tax_rate_id FROM ip_quote_tax_rates WHERE quote_tax_rate_id = ${removeId}`)).toEqual([]);
    expect(dbQuery(`SELECT quote_tax_rate_id FROM ip_quote_tax_rates WHERE quote_tax_rate_id = ${keepId}`)).toHaveLength(1);
  });
});

test.describe('Quotes — guest access', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('it redirects a guest to login and leaks no quote', async ({ page }) => {
    /* Arrange + Act */
    const response = await page.goto('/quotes/status/all');

    /* Assert */
    await expect(page).toHaveURL(/\/sessions\/login/);
    expect(await response.text()).not.toContain('QUO-SECRET');
  });
});

// ---- item amount / sign logic and message boundaries (GHSA-jvcw-w8pr-j92c) ---------------------------------------

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

const newItem = (quoteId, name, quantity, price, discount = '') => ({
  quote_id: String(quoteId), item_id: '', item_name: name, item_description: '', item_quantity: quantity, item_price: price,
  item_discount_amount: discount, item_product_id: '', item_product_unit_id: '', item_tax_rate_id: '0',
});
const existingItem = (quoteId, itemId, quantity = '2', price = '150') => ({ ...newItem(quoteId, 'Widget', quantity, price), item_id: String(itemId) });

/** POST quotes/ajax/save with `items`, the way the quote view does, and return the parsed JSON. */
async function saveItems(page, quoteId, items, extra = {}) {
  const form = {
    quote_id: String(quoteId), quote_status_id: '2', quote_number: `QSIGN-${quoteId}`,
    quote_date_created: new Date().toISOString().slice(0, 10), quote_date_expires: new Date(Date.now() + 15 * 86400000).toISOString().slice(0, 10),
    quote_password: '', notes: '', quote_discount_percent: '0', quote_discount_amount: '0', service_id: '', items: JSON.stringify(items), ...extra,
  };

  return (await page.request.post('/quotes/ajax/save', { headers: XHR, form })).json();
}

/** A saved 300.00 quote (2 x 150); returns its id and the id of its one item. */
async function quoteWithOneItem(page) {
  const quoteId = seedQuote(seedClientRow(), { quote_number: `QSIGN-${Date.now().toString(36)}` });
  const json = await saveItems(page, quoteId, [newItem(quoteId, 'Widget', '2', '150')]);
  expect(json.success, `setup save failed: ${JSON.stringify(json)}`).toBe(1);
  const [item] = dbQuery(`SELECT item_id FROM ip_quote_items WHERE quote_id = ${quoteId} AND item_name = 'Widget'`);

  return { quoteId, itemId: Number(item.item_id) };
}

const quoteTotal = (quoteId) => Number(dbQuery(`SELECT quote_total FROM ip_quote_amounts WHERE quote_id = ${quoteId}`)[0].quote_total);

test.describe('Quotes — item amounts and signs', () => {
  test.beforeEach(() => seedQuoteSettings());

  const NEGATIVE_AMOUNTS = {
    'negative quantity': ['-3', '150', '', 'item_quantity'],
    'negative price': ['3', '-150', '', 'item_price'],
    'negative discount': ['1', '10', '-5', 'item_discount_amount'],
  };

  for (const [label, [quantity, price, discount, field]] of Object.entries(NEGATIVE_AMOUNTS)) {
    test(`it rejects each negative amount (${label}) and keeps the quote unchanged`, async ({ page }) => {
      /* Arrange */
      const { quoteId, itemId } = await quoteWithOneItem(page);

      /* Act */
      const json = await saveItems(page, quoteId, [existingItem(quoteId, itemId), newItem(quoteId, 'Extra', quantity, price, discount)]);

      /* Assert */
      expect(json.success).toBe(0);
      expect(Object.keys(json.validation_errors)).toEqual([field]);
      expect(count('ip_quote_items', `quote_id = ${quoteId} AND item_name = 'Extra'`)).toBe(0);
      expect(quoteTotal(quoteId)).toBe(300);
    });
  }

  test('it rejects the double negative and a negative global discount', async ({ page }) => {
    /* Arrange */
    const { quoteId, itemId } = await quoteWithOneItem(page);

    /* Act */
    const json = await saveItems(page, quoteId, [existingItem(quoteId, itemId), newItem(quoteId, 'Adjustment', '-3', '-150')], { quote_discount_amount: '-10', quote_discount_percent: '-5' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(Object.keys(json.validation_errors).sort()).toEqual(['item_price', 'item_quantity', 'quote_discount_amount', 'quote_discount_percent']);
    expect(quoteTotal(quoteId)).toBe(300);
  });

  test('it rejects a discount percent above one hundred and keeps the quote unchanged', async ({ page }) => {
    /* Arrange */
    const { quoteId, itemId } = await quoteWithOneItem(page);

    /* Act */
    const json = await saveItems(page, quoteId, [existingItem(quoteId, itemId)], { quote_discount_percent: '101' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(json.validation_errors.quote_discount_percent).toBe('The discount percentage cannot exceed 100.');
    expect(quoteTotal(quoteId)).toBe(300);
  });

  test('it accepts a discount percent of exactly one hundred', async ({ page }) => {
    /* Arrange */
    const { quoteId, itemId } = await quoteWithOneItem(page);

    /* Act */
    const json = await saveItems(page, quoteId, [existingItem(quoteId, itemId)], { quote_discount_percent: '100' });

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
  });

  test('it still saves zero and positive amounts', async ({ page }) => {
    /* Arrange */
    const { quoteId, itemId } = await quoteWithOneItem(page);

    /* Act */
    const json = await saveItems(page, quoteId, [existingItem(quoteId, itemId), newItem(quoteId, 'Free sample', '0', '0'), newItem(quoteId, 'Extra', '2', '10')]);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(quoteTotal(quoteId)).toBe(320);
  });

  test('it refuses an item id of another quote or a missing one', async ({ page }) => {
    /* Arrange */
    const a = await quoteWithOneItem(page);
    const b = await quoteWithOneItem(page);
    const hijack = { ...existingItem(a.quoteId, b.itemId), item_price: '1' };

    /* Act */
    const foreign = await saveItems(page, a.quoteId, [existingItem(a.quoteId, a.itemId), hijack]);
    const missing = await saveItems(page, a.quoteId, [existingItem(a.quoteId, 987654)]);

    /* Assert */
    expect(foreign.validation_errors.item_id).toBe('One of the submitted items does not belong to this document.');
    expect(Object.keys(missing.validation_errors)).toContain('item_id');
    expect(count('ip_quote_items', `item_id = ${b.itemId} AND quote_id = ${b.quoteId} AND item_price = 150`)).toBe(1);
  });

  test('it saves a new item to the posted quote even if the item names another', async ({ page }) => {
    /* Arrange */
    const a = await quoteWithOneItem(page);
    const b = await quoteWithOneItem(page);

    /* Act */
    const json = await saveItems(page, a.quoteId, [newItem(b.quoteId, 'Misdirected', '1', '10')]);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(count('ip_quote_items', `quote_id = ${a.quoteId} AND item_name = 'Misdirected'`)).toBe(1);
    expect(count('ip_quote_items', `quote_id = ${b.quoteId} AND item_name = 'Misdirected'`)).toBe(0);
  });
});

test.describe('Quotes — amount and sign message boundaries', () => {
  test.beforeEach(() => seedQuoteSettings());

  const SINGLE_DISCOUNT_FIELDS = {
    'negative amount': [{ quote_discount_amount: '-1' }, 'quote_discount_amount', 'The discount cannot be negative.'],
    'negative percent': [{ quote_discount_percent: '-1' }, 'quote_discount_percent', 'The discount cannot be negative.'],
    'percent over 100': [{ quote_discount_percent: '100.5' }, 'quote_discount_percent', 'The discount percentage cannot exceed 100.'],
  };

  for (const [label, [extra, field, message]] of Object.entries(SINGLE_DISCOUNT_FIELDS)) {
    test(`it names exactly one field and message for each bad global discount (${label})`, async ({ page }) => {
      /* Arrange */
      const { quoteId, itemId } = await quoteWithOneItem(page);

      /* Act */
      const json = await saveItems(page, quoteId, [existingItem(quoteId, itemId, '2', '150')], extra);

      /* Assert */
      expect(json.success).toBe(0);
      expect(json.validation_errors).toEqual({ [field]: message });
      expect(quoteTotal(quoteId)).toBe(300);
    });
  }

  test('it uses the plain negative quantity message on a quote and accepts zero', async ({ page }) => {
    /* Arrange */
    const { quoteId, itemId } = await quoteWithOneItem(page);

    /* Act */
    const negative = await saveItems(page, quoteId, [existingItem(quoteId, itemId, '-2', '150')]);
    const zero = await saveItems(page, quoteId, [existingItem(quoteId, itemId, '0', '150')]);

    /* Assert */
    expect(negative.validation_errors).toEqual({ item_quantity: 'The item quantity cannot be negative.' });
    expect(zero.success, 'positive control: zero quantity is saved').toBe(1);
    expect(quoteTotal(quoteId)).toBe(0);
  });

  test('it checks a negative row that follows a valid new row', async ({ page }) => {
    /* Arrange */
    const { quoteId, itemId } = await quoteWithOneItem(page);

    /* Act */
    const json = await saveItems(page, quoteId, [existingItem(quoteId, itemId, '2', '150'), newItem(quoteId, 'Fine', '1', '10'), newItem(quoteId, 'Bad', '1', '-10')]);

    /* Assert */
    expect(json.validation_errors).toEqual({ item_price: 'The item price cannot be negative.' });
    expect(count('ip_quote_items', `quote_id = ${quoteId} AND item_name = 'Fine'`)).toBe(0);
    expect(quoteTotal(quoteId)).toBe(300);
  });
});
