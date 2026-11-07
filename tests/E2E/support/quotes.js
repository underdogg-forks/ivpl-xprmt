/**
 * Arrange-only builders for the quote specs: the rows the PHPUnit QuotesAjaxControllerTest seeds with
 * databaseInsert(), written straight to the database, plus the payload shapes quotes/ajax/save expects.
 */

import { dbExec, dbInsert, dbQuery } from './db.js';
import { uniq } from './fixtures.js';
import { loginAs } from './auth.js';

const today = () => new Date().toISOString().slice(0, 10);
const inDays = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
const now = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

/** Mdl_quotes::get_date_expires() reads this setting with no fallback; a real install seeds it during setup. */
export function seedQuoteSettings() {
  dbExec("INSERT IGNORE INTO ip_settings (setting_key, setting_value) VALUES ('quotes_expire_after', '15'), ('invoices_due_after', '30')");
}

export function seedClientRow(overrides = {}) {
  return dbInsert('ip_clients', {
    client_name: uniq('Client'),
    client_address_1: '1 Test Street',
    client_city: 'Testville',
    client_country: 'NL',
    client_active: 1,
    client_date_created: now(),
    client_date_modified: now(),
    ...overrides,
  });
}

export function seedQuote(clientId, overrides = {}) {
  const quoteId = dbInsert('ip_quotes', {
    user_id: 1,
    client_id: clientId,
    invoice_group_id: 1,
    quote_status_id: 2,
    quote_number: uniq('QUO'),
    quote_url_key: uniq('key').toLowerCase().padEnd(32, '0'),
    quote_date_created: today(),
    quote_date_modified: now(),
    quote_date_expires: inDays(15),
    ...overrides,
  });
  dbInsert('ip_quote_amounts', { quote_id: quoteId, quote_item_subtotal: '0.00', quote_item_tax_total: '0.00', quote_tax_total: '0.00', quote_total: '0.00' });

  return quoteId;
}

export function seedQuoteItem(quoteId, name, quantity = '1', price = '10') {
  return dbInsert('ip_quote_items', {
    quote_id: quoteId,
    item_name: name,
    item_description: '',
    item_quantity: quantity,
    item_price: price,
    item_order: 1,
    item_date_added: today(),
  });
}

export const itemPayload = (quoteId, name, quantity, price) => ({
  quote_id: String(quoteId),
  item_id: '',
  item_name: name,
  item_description: '',
  item_quantity: quantity,
  item_price: price,
  item_discount_amount: '',
  item_product_id: '',
  item_product_unit_id: '',
  item_tax_rate_id: '0',
});

export const savePayload = (quoteId) => ({
  quote_id: String(quoteId),
  quote_status_id: '2',
  quote_number: `QUO-SAVED-${quoteId}`,
  quote_date_created: today(),
  quote_date_expires: inDays(15),
  quote_password: '',
  notes: '',
  quote_discount_percent: '0',
  quote_discount_amount: '0',
  items: '[]',
});

export const quoteRow = (id) => dbQuery(`SELECT * FROM ip_quotes WHERE quote_id = ${Number(id)}`)[0] ?? null;

export const count = (table, where = '1=1') => Number(dbQuery(`SELECT COUNT(*) AS n FROM ${table} WHERE ${where}`)[0].n);

/**
 * A portal user (user_type 2) assigned to `clientId`, able to log in with the admin's password: the credential
 * columns are copied from the baseline admin row, so no hashing is needed here.
 */
export function seedGuestUser(clientId) {
  const [admin] = dbQuery('SELECT user_password, user_psalt FROM ip_users WHERE user_id = 1');
  const email = `${uniq('guest').toLowerCase()}@test.local`;
  const userId = dbInsert('ip_users', {
    user_type: 2,
    user_name: 'Portal Guest',
    user_email: email,
    user_password: admin.user_password,
    user_psalt: admin.user_psalt,
    user_language: 'system',
    user_active: 1,
    user_date_created: now(),
    user_date_modified: now(),
  });
  dbInsert('ip_user_clients', { user_id: userId, client_id: clientId });

  return { userId, email };
}

/** Log a portal user for `clientId` in on `baseURL` in its own browser context; the caller closes it. */
export async function guestSession(browser, clientId, baseURL) {
  const guest = seedGuestUser(clientId);

  return loginAs(browser, guest.email, 'password', baseURL);
}

export const QUOTE_STATUS = { DRAFT: 1, SENT: 2, VIEWED: 3, APPROVED: 4, REJECTED: 5 };

export function seedGuestQuote(clientId, status, number) {
  const quoteId = seedQuote(clientId, { quote_status_id: status, quote_number: number });
  dbExec(`UPDATE ip_quote_amounts SET quote_item_subtotal = 10, quote_total = 10 WHERE quote_id = ${quoteId}`);

  return quoteId;
}

export function setSetting(key, value) {
  dbExec(`DELETE FROM ip_settings WHERE setting_key = '${key}'`);
  dbInsert('ip_settings', { setting_key: key, setting_value: value });
}

/** A second admin (user_type 1, not the primary admin) who can log in with the baseline admin's password. */
export function seedAdminUser() {
  const [admin] = dbQuery('SELECT user_password, user_psalt FROM ip_users WHERE user_id = 1');
  const email = `${uniq('admin2').toLowerCase()}@test.local`;
  const userId = dbInsert('ip_users', {
    user_type: 1,
    user_name: uniq('Admin'),
    user_email: email,
    user_password: admin.user_password,
    user_psalt: admin.user_psalt,
    user_language: 'system',
    user_active: 1,
    user_date_created: now(),
    user_date_modified: now(),
  });

  return { userId, email };
}
