/**
 * Arrange-only builders and request helpers for the invoice amount specs: what the PHPUnit
 * InvoiceItemAmountSignTest seeds with seedInvoice()/databaseInsert(), plus the invoices/ajax/save payload.
 */

import { expect } from '../test.js';
import { dbExec, dbInsert, dbQuery } from './db.js';
import { uniq } from './fixtures.js';
import { seedClientRow } from './quotes.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };
const today = () => new Date().toISOString().slice(0, 10);
const now = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

export function seedInvoiceSettings() {
  dbExec("INSERT IGNORE INTO ip_settings (setting_key, setting_value) VALUES ('invoices_due_after', '30')");
}

/** Like the PHPUnit seedInvoice(): the invoice row plus the ip_invoice_amounts row INNER JOINs need. */
export function seedInvoiceRow(clientId, overrides = {}) {
  const invoiceId = dbInsert('ip_invoices', {
    user_id: 1,
    client_id: clientId,
    invoice_group_id: 1,
    invoice_status_id: 1,
    invoice_date_created: today(),
    invoice_date_modified: now(),
    invoice_date_due: new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
    invoice_time_created: new Date().toTimeString().slice(0, 8),
    payment_method: 1,
    invoice_discount_amount: '0.00',
    invoice_discount_percent: '0.00',
    invoice_number: uniq('INV'),
    invoice_terms: '',
    invoice_url_key: uniq('key').toLowerCase().padEnd(32, '0'),
    ...overrides,
  });
  dbInsert('ip_invoice_amounts', {
    invoice_id: invoiceId,
    invoice_item_subtotal: '0.00',
    invoice_item_tax_total: '0.00',
    invoice_tax_total: '0.00',
    invoice_total: '0.00',
    invoice_paid: '0.00',
    invoice_balance: '0.00',
    invoice_sign: 1,
  });

  return invoiceId;
}

export const newItem = (invoiceId, name, quantity, price, discount = '') => ({
  invoice_id: String(invoiceId), item_id: '', item_name: name, item_description: '', item_quantity: quantity, item_price: price,
  item_discount_amount: discount, item_product_id: '', item_product_unit_id: '', item_task_id: '', item_tax_rate_id: '0',
});

export const existingItem = (invoiceId, itemId, quantity = '2', price = '150') => ({ ...newItem(invoiceId, 'Widget', quantity, price), item_id: String(itemId) });

/** POST invoices/ajax/save with `items`, the way the invoice view does, and return the parsed JSON. */
export async function saveItems(page, invoiceId, items, extra = {}) {
  const form = {
    invoice_id: String(invoiceId),
    invoice_number: `SIGN-${invoiceId}`,
    invoice_date_created: today(),
    invoice_date_due: new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
    invoice_time_created: new Date().toTimeString().slice(0, 8),
    invoice_status_id: '2',
    invoice_discount_percent: '0',
    invoice_discount_amount: '0',
    items: JSON.stringify(items),
    ...extra,
  };

  return (await page.request.post('/invoices/ajax/save', { headers: XHR, form })).json();
}

/** A saved 300.00 invoice (2 x 150); returns its id and the id of its one item. */
export async function invoiceWithOneItem(page) {
  const invoiceId = seedInvoiceRow(seedClientRow(), { invoice_number: uniq('SIGN') });
  const json = await saveItems(page, invoiceId, [newItem(invoiceId, 'Widget', '2', '150')]);
  expect(json.success, `setup save failed: ${JSON.stringify(json)}`).toBe(1);
  const [item] = dbQuery(`SELECT item_id FROM ip_invoice_items WHERE invoice_id = ${invoiceId} AND item_name = 'Widget'`);
  expect(invoiceTotal(invoiceId)).toBe(300);

  return { invoiceId, itemId: Number(item.item_id) };
}

export async function creditInvoice(page) {
  const { invoiceId: sourceId } = await invoiceWithOneItem(page);
  const [source] = dbQuery(`SELECT client_id FROM ip_invoices WHERE invoice_id = ${sourceId}`);
  const json = await (await page.request.post('/invoices/ajax/create_credit', {
    headers: XHR,
    form: {
      client_id: String(source.client_id), invoice_date_created: today(), invoice_time_created: new Date().toTimeString().slice(0, 8),
      invoice_group_id: '1', user_id: '1', invoice_id: String(sourceId),
    },
  })).json();
  expect(json.success, `credit create failed: ${JSON.stringify(json)}`).toBe(1);

  return { creditId: Number(json.invoice_id), sourceId };
}

export const invoiceTotal = (id) => Number(dbQuery(`SELECT invoice_total FROM ip_invoice_amounts WHERE invoice_id = ${Number(id)}`)[0].invoice_total);
export const invoiceRow = (id) => dbQuery(`SELECT * FROM ip_invoices WHERE invoice_id = ${Number(id)}`)[0] ?? null;
export const itemRow = (id) => dbQuery(`SELECT * FROM ip_invoice_items WHERE item_id = ${Number(id)}`)[0] ?? null;
export const countInvoiceRows = (table, where = '1=1') => Number(dbQuery(`SELECT COUNT(*) AS n FROM ${table} WHERE ${where}`)[0].n);

/** A regular 300.00 invoice plus the "small" (10.00) row that deleting triggers a recalculation with. */
export function smallRow(invoiceId) {
  const itemId = dbInsert('ip_invoice_items', { invoice_id: invoiceId, item_name: 'Small', item_description: '', item_quantity: '1', item_price: '10', item_order: 2, item_date_added: '2026-10-04' });
  dbInsert('ip_invoice_item_amounts', { item_id: itemId, item_subtotal: '10.00', item_tax_total: '0.00', item_discount: '0.00', item_total: '10.00' });

  return itemId;
}

/** A line inserted behind the controller's back: -10 x 50 = -500 against 300 + 10. */
export function smuggleBadRow(invoiceId) {
  const itemId = dbInsert('ip_invoice_items', { invoice_id: invoiceId, item_name: 'Smuggled', item_description: '', item_quantity: '-10', item_price: '50', item_order: 3, item_date_added: '2026-10-04' });
  dbInsert('ip_invoice_item_amounts', { item_id: itemId, item_subtotal: '-500.00', item_tax_total: '0.00', item_discount: '0.00', item_total: '-500.00' });

  return itemId;
}

export const invoiceAmounts = (id) => dbQuery(`SELECT * FROM ip_invoice_amounts WHERE invoice_id = ${Number(id)}`)[0];
