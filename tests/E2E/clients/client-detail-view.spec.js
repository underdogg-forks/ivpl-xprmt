/**
 * Browser coverage for tests/Feature/Clients/ClientDetailViewTest.php: clients/view (the client detail page: billing
 * totals, only this client's invoices, quotes, payments and notes, paginated tabs, 404 for an unknown client) and the
 * branches of clients/form the basic CRUD specs do not reach (cancel, custom title, e-invoicing reset, and handing a new
 * client to the users who have access to all clients).
 */

import { test, expect } from '../test.js';
import { dbExec, dbInsert } from '../support/db.js';
import { postForm } from '../support/http.js';
import { seedInvoiceRow } from '../support/invoices.js';
import { count, seedClientRow, seedQuote, setSetting } from '../support/quotes.js';

const today = () => new Date().toISOString().slice(0, 10);

function seedInvoiceWithAmounts(clientId, total, paid, balance, overrides = {}) {
  const invoiceId = seedInvoiceRow(clientId, overrides);
  dbExec(`UPDATE ip_invoice_amounts SET invoice_total = ${total}, invoice_paid = ${paid}, invoice_balance = ${balance} WHERE invoice_id = ${invoiceId}`);

  return invoiceId;
}

const seedPayment = (invoiceId, note) => dbInsert('ip_payments', { invoice_id: invoiceId, payment_method_id: 1, payment_amount: '100.00', payment_date: today(), payment_note: note });

/** An "all clients" or restricted portal user, without a password: only the assignment rows matter here. */
const seedGuest = (email, allClients) => dbInsert('ip_users', {
  user_type: 2, user_name: email, user_email: email, user_all_clients: allClients, user_password: 'x', user_psalt: 'e2e', user_language: 'system', user_active: 1,
  user_date_created: '2026-10-04 12:00:00', user_date_modified: '2026-10-04 12:00:00',
});

test.describe('Client detail page', () => {
  test('it shows the clients billing totals', async ({ page }) => {
    /* Arrange: billed 121 + 50 = 171, paid 21, outstanding 100 + 50 = 150 */
    const clientId = seedClientRow({ client_name: 'Totals Client' });
    seedInvoiceWithAmounts(clientId, '121.00', '21.00', '100.00');
    seedInvoiceWithAmounts(clientId, '50.00', '0.00', '50.00');
    seedInvoiceWithAmounts(seedClientRow({ client_name: 'Someone Else' }), '9999.00', '0.00', '9999.00');

    /* Act */
    const response = await page.goto(`/clients/view/${clientId}`);
    const html = await response.text();

    /* Assert */
    expect(response.status()).toBe(200);
    expect(html).toContain('Totals Client');
    expect(html, 'total billed').toMatch(/td-amount">\s*\$171\s*</);
    expect(html, 'total paid').toMatch(/td-amount">\s*\$21\s*</);
    expect(html, 'outstanding balance').toMatch(/td-amount">\s*\$150\s*</);
    expect(html).not.toContain('9999');
  });

  test('it lists only this clients invoices, quotes and payments and notes', async ({ page }) => {
    /* Arrange */
    const mine = seedClientRow({ client_name: 'Mine Client' });
    const other = seedClientRow({ client_name: 'Other Client' });
    const myInvoice = seedInvoiceRow(mine, { invoice_number: 'MINE-INV-1' });
    seedInvoiceRow(other, { invoice_number: 'OTHER-INV-1' });
    seedPayment(myInvoice, 'MINE-PAYMENT-NOTE');
    seedPayment(seedInvoiceRow(other, { invoice_number: 'OTHER-INV-2' }), 'OTHER-PAYMENT-NOTE');
    seedQuote(mine, { quote_number: 'MINE-QUOTE-1' });
    seedQuote(other, { quote_number: 'OTHER-QUOTE-1' });
    dbInsert('ip_client_notes', { client_id: mine, client_note_date: today(), client_note: 'MINE-NOTE-TEXT' });
    dbInsert('ip_client_notes', { client_id: other, client_note_date: today(), client_note: 'OTHER-NOTE-TEXT' });

    /* Act */
    const html = await (await page.goto(`/clients/view/${mine}`)).text();

    /* Assert */
    for (const own of ['MINE-INV-1', 'MINE-PAYMENT-NOTE', 'MINE-QUOTE-1']) expect(html).toContain(own);
    for (const foreign of ['OTHER-INV-1', 'OTHER-INV-2', 'OTHER-PAYMENT-NOTE', 'OTHER-QUOTE-1', 'OTHER-NOTE-TEXT']) expect(html).not.toContain(foreign);
  });

  test('it paginates a clients invoices from the tab url', async ({ page }) => {
    /* Arrange: 5 invoices, 2 per page */
    setSetting('default_list_limit', '2');
    const clientId = seedClientRow();
    for (const n of [1, 2, 3, 4, 5]) seedInvoiceRow(clientId, { invoice_number: `PAGE-INV-${n}` });

    /* Act */
    const seen = [];
    for (const offset of [0, 2, 4]) {
      const html = await (await page.request.get(`/clients/view/${clientId}/invoices/${offset}`)).text();
      seen.push([...new Set(html.match(/PAGE-INV-\d/g) ?? [])]);
    }

    /* Assert: 2 + 2 + 1 distinct invoices, no overlap, none missing */
    expect(seen.map((list) => list.length)).toEqual([2, 2, 1]);
    expect(new Set(seen.flat()).size).toBe(5);
  });

  test('it returns 404 for an unknown client', async ({ page }) => {
    /* Act */
    const response = await page.request.get('/clients/view/999999');

    /* Assert */
    expect(response.status()).toBe(404);
  });
});

test.describe('Client form branches', () => {
  test('it returns to the list when the client form is cancelled', async ({ page }) => {
    /* Act: the server branch of a cancelled form post */
    const response = await postForm(page, '/clients/form', { btn_cancel: '1', client_name: 'Never Saved' });

    /* Assert */
    expect(response.status()).toBe(303);
    expect(new URL(response.headers().location, 'http://localhost').pathname.replace(/^\/index\.php/, '')).toMatch(/^\/clients(\/status\/\w+)?$/);
    expect(count('ip_clients', "client_name = 'Never Saved'")).toBe(0);
  });

  test('it goes back to the list when the cancel button of the client form is clicked', async ({ page }) => {
    /* Arrange: the cancel button is history.back(), so arrive at the form from the list */
    await page.goto('/clients');
    const listUrl = page.url();
    await page.goto('/clients/form');
    await page.fill('#client_name', 'Never Saved Either');

    /* Act */
    await page.click('#btn-cancel');

    /* Assert */
    await expect(page).toHaveURL(listUrl);
    expect(count('ip_clients', "client_name = 'Never Saved Either'")).toBe(0);
  });

  test('it returns 404 when editing an unknown client', async ({ page }) => {
    /* Act */
    const response = await page.request.get('/clients/form/999999');

    /* Assert */
    expect(response.status()).toBe(404);
  });

  test('it stores a custom title instead of the custom marker', async ({ page }) => {
    /* Act */
    await postForm(page, '/clients/form', { client_name: 'Titled Client', client_title: 'custom', client_title_custom: 'Dr. Prof.', is_update: '0', btn_submit: '1' });

    /* Assert */
    expect(count('ip_clients', "client_name = 'Titled Client' AND client_title = 'Dr. Prof.'")).toBe(1);
  });

  test('it clears the einvoicing version when einvoicing is switched off', async ({ page }) => {
    /* Arrange */
    const clientId = seedClientRow({ client_name: 'E-Invoicing Client', client_einvoicing_version: 'Facturxv10' });

    /* Act */
    await postForm(page, `/clients/form/${clientId}`, { client_name: 'E-Invoicing Client', client_start_einvoicing: '0', client_einvoicing_version: 'Facturxv10', is_update: '1', btn_submit: '1' });

    /* Assert */
    expect(count('ip_clients', `client_id = ${clientId} AND client_einvoicing_version = ''`)).toBe(1);
  });

  test('it gives users with all clients access to a newly created client', async ({ page }) => {
    /* Arrange: one "all clients" user, one restricted user */
    const allClients = seedGuest('all-clients@test.local', 1);
    const restricted = seedGuest('restricted@test.local', 0);

    /* Act */
    await postForm(page, '/clients/form', { client_name: 'Auto Assigned', client_active: '1', is_update: '0', btn_submit: '1' });

    /* Assert */
    const newClient = count('ip_clients', "client_name = 'Auto Assigned'");
    expect(newClient).toBe(1);
    expect(count('ip_user_clients', `user_id = ${allClients} AND client_id IN (SELECT client_id FROM ip_clients WHERE client_name = 'Auto Assigned')`)).toBe(1);
    expect(count('ip_user_clients', `user_id = ${restricted}`)).toBe(0);
  });

  test('it does not hand an inactive new client to all clients users', async ({ page }) => {
    /* Arrange */
    const allClients = seedGuest('all-clients@test.local', 1);

    /* Act */
    await postForm(page, '/clients/form', { client_name: 'Dormant Client', client_active: '0', is_update: '0', btn_submit: '1' });

    /* Assert */
    expect(count('ip_clients', "client_name = 'Dormant Client'")).toBe(1);
    expect(count('ip_user_clients', `user_id = ${allClients}`)).toBe(0);
  });

  test('it does not reassign users when an existing client is updated', async ({ page }) => {
    /* Arrange */
    const allClients = seedGuest('all-clients@test.local', 1);
    const clientId = seedClientRow({ client_name: 'Already There' });

    /* Act */
    await postForm(page, `/clients/form/${clientId}`, { client_name: 'Already There', is_update: '1', btn_submit: '1' });

    /* Assert */
    expect(count('ip_user_clients', `user_id = ${allClients} AND client_id = ${clientId}`)).toBe(0);
  });
});
