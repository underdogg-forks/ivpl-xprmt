/**
 * Browser coverage for tests/Feature/Invoices/InvoiceItemAmountSignTest.php: sign validation on invoice save.
 * A negative quantity, price or discount used to be stored and summed as-is, so (-3) x (-150) inflated a 300.00
 * invoice to 750.00 and (-10) x 50 turned it into a -200.00 bill. Every rejection must also leave the stored
 * invoice untouched.
 */

import { test, expect } from '../test.js';
import { dbExec, dbInsert, dbQuery } from '../support/db.js';
import {
  countInvoiceRows, creditInvoice, existingItem, invoiceRow, invoiceTotal, invoiceWithOneItem, itemRow, newItem, saveItems, seedInvoiceSettings,
} from '../support/invoices.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

test.describe('Invoice amounts — the two reported attacks', () => {
  test.beforeEach(() => seedInvoiceSettings());

  test('it rejects the double negative that inflates the total', async ({ page }) => {
    /* Arrange: a 300.00 invoice (2 x 150) */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);

    /* Act: add a line of -3 x -150 = +450 */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId), newItem(invoiceId, 'Adjustment', '-3', '-150')]);

    /* Assert */
    expect(json.success).toBe(0);
    expect(json.validation_errors.item_quantity).toBe('The item quantity cannot be negative.');
    expect(json.validation_errors.item_price).toBe('The item price cannot be negative.');
    expect(countInvoiceRows('ip_invoice_items', `invoice_id = ${invoiceId} AND item_name = 'Adjustment'`)).toBe(0);
    expect(invoiceTotal(invoiceId)).toBe(300);
  });

  test('it rejects the hidden credit line that makes the bill negative', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId), newItem(invoiceId, 'Adjustment', '-10', '50')]);

    /* Assert */
    expect(json.success).toBe(0);
    expect(Object.keys(json.validation_errors)).toContain('item_quantity');
    expect(Object.keys(json.validation_errors)).not.toContain('item_price');
    expect(countInvoiceRows('ip_invoice_items', `invoice_id = ${invoiceId} AND item_name = 'Adjustment'`)).toBe(0);
    expect(invoiceTotal(invoiceId)).toBe(300);
  });

  const NEGATIVE_AMOUNTS = {
    'negative quantity': ['-1', '10', '', 'item_quantity'],
    'negative price': ['1', '-10', '', 'item_price'],
    'negative discount': ['1', '10', '-5', 'item_discount_amount'],
    'localised negative': ['-1,5', '10', '', 'item_quantity'],
  };

  for (const [label, [quantity, price, discount, field]] of Object.entries(NEGATIVE_AMOUNTS)) {
    test(`it rejects each negative amount on its own (${label})`, async ({ page }) => {
      /* Arrange */
      const { invoiceId, itemId } = await invoiceWithOneItem(page);

      /* Act */
      const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId), newItem(invoiceId, 'Extra', quantity, price, discount)]);

      /* Assert */
      expect(json.success).toBe(0);
      expect(Object.keys(json.validation_errors)).toEqual([field]);
      expect(countInvoiceRows('ip_invoice_items', `invoice_id = ${invoiceId} AND item_name = 'Extra'`)).toBe(0);
      expect(invoiceTotal(invoiceId)).toBe(300);
    });
  }

  test('it rejects a negative global discount amount and percent', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId)], { invoice_discount_amount: '-50', invoice_discount_percent: '-10' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(json.validation_errors.invoice_discount_amount).toBe('The discount cannot be negative.');
    expect(json.validation_errors.invoice_discount_percent).toBe('The discount cannot be negative.');
    expect(invoiceTotal(invoiceId)).toBe(300);
  });

  const DISCOUNTS_LARGER_THAN_THE_INVOICE = {
    'amount above the subtotal': { invoice_discount_amount: '700' },
    'percent above one hundred': { invoice_discount_percent: '150' },
  };

  for (const [label, discount] of Object.entries(DISCOUNTS_LARGER_THAN_THE_INVOICE)) {
    test(`it rolls back the whole save when the discounts would make the total negative (${label})`, async ({ page }) => {
      /* Arrange */
      const { invoiceId, itemId } = await invoiceWithOneItem(page);

      /* Act */
      const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId, '3', '200')], discount);

      /* Assert */
      expect(json.success, JSON.stringify(json)).toBe(0);
      expect(json.validation_errors.invoice_total).toBe('The discounts exceed the invoice total, so the total would be negative.');
      expect(Number(itemRow(itemId).item_price), 'the item edit must be rolled back').toBe(150);
      expect(Number(invoiceRow(invoiceId).invoice_discount_amount)).toBe(0);
      expect(Number(invoiceRow(invoiceId).invoice_discount_percent)).toBe(0);
      expect(invoiceTotal(invoiceId)).toBe(300);
    });
  }

  test('it rolls back earlier items when a later line has no name', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId, '3', '200'), newItem(invoiceId, '', '1', '10')]);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(0);
    expect(Object.keys(json.validation_errors ?? {})).toContain('item_name');
    expect(Number(itemRow(itemId).item_price)).toBe(150);
    expect(Number(itemRow(itemId).item_quantity)).toBe(2);
    expect(invoiceTotal(invoiceId)).toBe(300);
  });

  test('it rolls back the items when the invoice number is invalid', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId, '3', '200')], { invoice_number: 'bad<number>' });

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(0);
    expect(Object.keys(json.validation_errors ?? {})).toContain('invoice_number');
    expect(Number(itemRow(itemId).item_price)).toBe(150);
    expect(Number(itemRow(itemId).item_quantity)).toBe(2);
    expect(invoiceTotal(invoiceId)).toBe(300);
  });

  test('it accepts a discount that brings the total to exactly zero', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId)], { invoice_discount_amount: '300' });

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(invoiceTotal(invoiceId)).toBe(0);
  });
});

test.describe('Invoice amounts — what must keep working', () => {
  test.beforeEach(() => seedInvoiceSettings());

  test('it still saves zero and positive amounts', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId), newItem(invoiceId, 'Free sample', '0', '0'), newItem(invoiceId, 'Paid extra', '3', '10', '5')]);

    /* Assert: 300 + 3 x (10 - 5), the item discount is per unit */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(countInvoiceRows('ip_invoice_items', `invoice_id = ${invoiceId} AND item_name = 'Free sample'`)).toBe(1);
    expect(invoiceTotal(invoiceId)).toBe(315);
  });

  test('it writes nothing when a later item is invalid', async ({ page }) => {
    /* Arrange: the first item is valid and changes the stored price, the second is not */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);
    const edited = { ...existingItem(invoiceId, itemId), item_price: '999' };

    /* Act */
    await saveItems(page, invoiceId, [edited, newItem(invoiceId, 'Bad', '-1', '10')]);

    /* Assert: the valid edit before the bad line was not applied either */
    expect(Number(itemRow(itemId).item_price)).toBe(150);
    expect(invoiceTotal(invoiceId)).toBe(300);
  });

  const SMALL_NON_NEGATIVE_TOTALS = { zero: ['0', 0], 'one unit': ['1', 1] };

  for (const [label, [price, expectedTotal]] of Object.entries(SMALL_NON_NEGATIVE_TOTALS)) {
    test(`it stores a zero or smallest positive total for a regular invoice (${label})`, async ({ page }) => {
      /* Arrange */
      const { invoiceId, itemId } = await invoiceWithOneItem(page);

      /* Act */
      const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId, '1', price)]);

      /* Assert */
      expect(json.success, JSON.stringify(json)).toBe(1);
      expect(invoiceTotal(invoiceId)).toBe(expectedTotal);
    });
  }
});

test.describe('Invoice amounts — items of other invoices', () => {
  test.beforeEach(() => seedInvoiceSettings());

  test('it refuses an item id that belongs to another invoice', async ({ page }) => {
    /* Arrange */
    const a = await invoiceWithOneItem(page);
    const b = await invoiceWithOneItem(page);
    const hijack = { ...existingItem(a.invoiceId, b.itemId), item_price: '1' };

    /* Act */
    const json = await saveItems(page, a.invoiceId, [existingItem(a.invoiceId, a.itemId), hijack]);

    /* Assert */
    expect(json.success).toBe(0);
    expect(json.validation_errors.item_id).toBe('One of the submitted items does not belong to this document.');
    expect(countInvoiceRows('ip_invoice_items', `item_id = ${b.itemId} AND invoice_id = ${b.invoiceId} AND item_price = 150`)).toBe(1);
    expect(invoiceTotal(b.invoiceId)).toBe(300);
  });

  test('it refuses an item id that does not exist', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId), existingItem(invoiceId, 987654)]);

    /* Assert */
    expect(json.success).toBe(0);
    expect(Object.keys(json.validation_errors)).toContain('item_id');
  });

  test('it saves a new item to the posted invoice even if the item names another', async ({ page }) => {
    /* Arrange */
    const a = await invoiceWithOneItem(page);
    const b = await invoiceWithOneItem(page);

    /* Act */
    const json = await saveItems(page, a.invoiceId, [newItem(b.invoiceId, 'Misdirected', '1', '10')]);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(countInvoiceRows('ip_invoice_items', `invoice_id = ${a.invoiceId} AND item_name = 'Misdirected'`)).toBe(1);
    expect(countInvoiceRows('ip_invoice_items', `invoice_id = ${b.invoiceId} AND item_name = 'Misdirected'`)).toBe(0);
  });
});

test.describe('Invoice amounts — credit invoices', () => {
  test.beforeEach(() => seedInvoiceSettings());

  test('it creates a credit invoice with a negative total', async ({ page }) => {
    /* Arrange */
    const { invoiceId: sourceId } = await invoiceWithOneItem(page);
    const [source] = dbQuery(`SELECT client_id FROM ip_invoices WHERE invoice_id = ${sourceId}`);

    /* Act */
    const json = await (await page.request.post('/invoices/ajax/create_credit', {
      headers: XHR,
      form: {
        client_id: String(source.client_id), invoice_date_created: new Date().toISOString().slice(0, 10), invoice_time_created: new Date().toTimeString().slice(0, 8),
        invoice_group_id: '1', user_id: '1', invoice_id: String(sourceId),
      },
    })).json();

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    const [credit] = dbQuery(`SELECT invoice_total, invoice_sign FROM ip_invoice_amounts WHERE invoice_id = ${Number(json.invoice_id)}`);
    expect(Number(credit.invoice_total), 'a credit invoice legitimately totals less than zero').toBe(-300);
    expect(Number(credit.invoice_sign)).toBe(-1);
    expect(Number(invoiceRow(json.invoice_id).creditinvoice_parent_id)).toBe(sourceId);
    const bystander = await invoiceWithOneItem(page);
    expect(countInvoiceRows('ip_invoices', `creditinvoice_parent_id = ${sourceId}`), 'only the new credit invoice points at its source').toBe(1);
    expect(Number(invoiceRow(sourceId).creditinvoice_parent_id)).toBe(0);
    expect(Number(invoiceRow(bystander.invoiceId).creditinvoice_parent_id)).toBe(0);
  });

  test('it still lets a credit invoice be saved with its negative quantities', async ({ page }) => {
    /* Arrange */
    const { creditId } = await creditInvoice(page);
    const [item] = dbQuery(`SELECT item_id FROM ip_invoice_items WHERE invoice_id = ${creditId}`);

    /* Act */
    const json = await saveItems(page, creditId, [existingItem(creditId, Number(item.item_id), '-2', '150')]);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(invoiceTotal(creditId)).toBe(-300);
  });

  test('it rejects a positive quantity or a negative price on a credit invoice', async ({ page }) => {
    /* Arrange */
    const { creditId } = await creditInvoice(page);
    const [item] = dbQuery(`SELECT item_id FROM ip_invoice_items WHERE invoice_id = ${creditId}`);

    /* Act */
    const positive = await saveItems(page, creditId, [existingItem(creditId, Number(item.item_id), '2', '150')]);
    const negative = await saveItems(page, creditId, [existingItem(creditId, Number(item.item_id), '-2', '-150')]);

    /* Assert */
    expect(positive.validation_errors.item_quantity).toBe('Quantities on a credit invoice must be zero or negative.');
    expect(negative.validation_errors.item_price).toBe('The item price cannot be negative.');
    expect(invoiceTotal(creditId)).toBe(-300);
  });

  test('it treats a legacy credit invoice marked only by its sign as a credit invoice', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);
    dbExec(`UPDATE ip_invoice_amounts SET invoice_sign = '-1' WHERE invoice_id = ${invoiceId}`);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId, '-2', '150')]);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(invoiceTotal(invoiceId)).toBe(-300);
  });

  test('it treats an invoice that names a credited parent as a credit invoice', async ({ page }) => {
    /* Arrange */
    const { invoiceId, itemId } = await invoiceWithOneItem(page);
    dbExec(`UPDATE ip_invoices SET creditinvoice_parent_id = 1 WHERE invoice_id = ${invoiceId}`);

    /* Act */
    const json = await saveItems(page, invoiceId, [existingItem(invoiceId, itemId, '-2', '150')]);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(invoiceTotal(invoiceId)).toBe(-300);
  });
});

test.describe('Invoice amounts — defence in depth', () => {
  test.beforeEach(() => seedInvoiceSettings());

  test('it refuses to store a negative total for a regular invoice even if a bad row got in', async ({ page }) => {
    /* Arrange: a bad line inserted behind the controller's back (-10 x 50 = -500 against 300 + 10) */
    const { invoiceId } = await invoiceWithOneItem(page);
    const deletable = dbInsert('ip_invoice_items', { invoice_id: invoiceId, item_name: 'Small', item_description: '', item_quantity: '1', item_price: '10', item_order: 2, item_date_added: '2026-10-04' });
    dbInsert('ip_invoice_item_amounts', { item_id: deletable, item_subtotal: '10.00', item_tax_total: '0.00', item_discount: '0.00', item_total: '10.00' });
    const bad = dbInsert('ip_invoice_items', { invoice_id: invoiceId, item_name: 'Smuggled', item_description: '', item_quantity: '-10', item_price: '50', item_order: 3, item_date_added: '2026-10-04' });
    dbInsert('ip_invoice_item_amounts', { item_id: bad, item_subtotal: '-500.00', item_tax_total: '0.00', item_discount: '0.00', item_total: '-500.00' });
    const before = invoiceTotal(invoiceId);

    /* Act: any recalculation (deleting the small item) now sums to 300 - 500 = -200 */
    await page.request.post(`/invoices/ajax/delete_item/${invoiceId}`, { headers: XHR, form: { item_id: String(deletable) } });

    /* Assert */
    expect(invoiceTotal(invoiceId), 'the stored total is kept, never driven negative').toBe(before);
    expect(invoiceTotal(invoiceId)).toBeGreaterThanOrEqual(0);
  });
});
