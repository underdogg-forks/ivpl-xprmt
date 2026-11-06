/**
 * Browser-side mirror of tests/Feature/Core/CsrfMutationParityTest.php (#1694): the state-changing endpoints
 * that have no CRUD controller test of their own run with CSRF protection ON, the production setting, and the
 * mutation must happen with a valid token and must not happen without one. Runs in the chromium-csrf project.
 *
 * A "valid token" is the one a rendered page carries in its head, sent with the cookie the server set for it.
 */

import crypto from 'node:crypto';
import { test, expect } from '../../test.js';
import { dbInsert, dbQuery, dbExec } from '../../support/db.js';
import { seedUser } from '../../support/fixtures.js';
import { seedInvoiceRow } from '../../support/invoices.js';
import { count, seedClientRow, seedQuote } from '../../support/quotes.js';

const tokenFrom = async (page, path) => {
  await page.goto(path);

  return page.locator('meta[name="csrf_token_value"]').getAttribute('content');
};
const postWithToken = async (page, url, form = {}) => page.request.post(url, { form: { ...form, _ip_csrf: await tokenFrom(page, '/dashboard') }, maxRedirects: 0 });
const postWithoutToken = (page, url, form = {}) => page.request.post(url, { form, maxRedirects: 0 });
const isRedirect = (response) => response.status() >= 300 && response.status() < 400;
const now = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

test.describe('CSRF parity — import/delete', () => {
  test('it deletes an import batch with a valid csrf token', async ({ page }) => {
    /* Arrange */
    const id = dbInsert('ip_imports', { import_date: now() });

    /* Act */
    const response = await postWithToken(page, `/import/delete/${id}`);

    /* Assert */
    expect(isRedirect(response), 'a valid-token delete redirects').toBe(true);
    expect(count('ip_imports', `import_id = ${id}`)).toBe(0);
  });

  test('it does not delete an import batch without a csrf token', async ({ page }) => {
    /* Arrange */
    const id = dbInsert('ip_imports', { import_date: now() });

    /* Act */
    const response = await postWithoutToken(page, `/import/delete/${id}`);

    /* Assert */
    expect(isRedirect(response), 'a token-less delete must not reach the controller').toBe(false);
    expect(count('ip_imports', `import_id = ${id}`)).toBe(1);
  });
});

test.describe('CSRF parity — users/delete_user_client', () => {
  const assigned = () => {
    const user = seedUser();
    const ucId = dbInsert('ip_user_clients', { user_id: user.id, client_id: seedClientRow() });

    return { userId: user.id, ucId };
  };

  test('it unassigns a client from a user with a valid csrf token', async ({ page }) => {
    /* Arrange */
    const { userId, ucId } = assigned();

    /* Act */
    const response = await postWithToken(page, `/users/delete_user_client/${userId}/${ucId}`);

    /* Assert */
    expect(isRedirect(response), 'a valid-token unassign redirects').toBe(true);
    expect(count('ip_user_clients', `user_client_id = ${ucId}`)).toBe(0);
  });

  test('it does not unassign a client from a user without a csrf token', async ({ page }) => {
    /* Arrange */
    const { userId, ucId } = assigned();

    /* Act */
    const response = await postWithoutToken(page, `/users/delete_user_client/${userId}/${ucId}`);

    /* Assert */
    expect(isRedirect(response), 'a token-less request must not reach the controller').toBe(false);
    expect(count('ip_user_clients', `user_client_id = ${ucId}`)).toBe(1);
  });
});

test.describe('CSRF parity — payments/delete', () => {
  const seedPayment = (note) => dbInsert('ip_payments', { invoice_id: seedInvoiceRow(seedClientRow()), payment_method_id: 1, payment_amount: '100.00', payment_date: now().slice(0, 10), payment_note: note });

  test('it deletes a payment with a valid csrf token', async ({ page }) => {
    /* Arrange */
    const id = seedPayment('parity-delete');

    /* Act */
    const response = await postWithToken(page, `/payments/delete/${id}`);

    /* Assert */
    expect(isRedirect(response), 'a valid-token delete redirects').toBe(true);
    expect(count('ip_payments', `payment_id = ${id}`)).toBe(0);
  });

  test('it does not delete a payment without a csrf token', async ({ page }) => {
    /* Arrange */
    const id = seedPayment('parity-keep');

    /* Act */
    const response = await postWithoutToken(page, `/payments/delete/${id}`);

    /* Assert */
    expect(isRedirect(response), 'a token-less delete must not reach the controller').toBe(false);
    expect(count('ip_payments', `payment_id = ${id}`)).toBe(1);
  });
});

test.describe('CSRF parity — recalculate_all_*', () => {
  test('it recalculates invoice amounts with a valid csrf token', async ({ page }) => {
    /* Arrange */
    const invoiceId = seedInvoiceRow(seedClientRow());
    dbExec(`UPDATE ip_invoice_amounts SET invoice_total = 999.99, invoice_balance = 999.99 WHERE invoice_id = ${invoiceId}`);

    /* Act */
    await postWithToken(page, '/invoices/recalculate_all_invoices');

    /* Assert */
    expect(count('ip_invoice_amounts', `invoice_id = ${invoiceId} AND invoice_total = 999.99`)).toBe(0);
    expect(count('ip_invoice_amounts', `invoice_id = ${invoiceId} AND invoice_total = 0`)).toBe(1);
  });

  test('it does not recalculate invoice amounts without a csrf token', async ({ page }) => {
    /* Arrange */
    const invoiceId = seedInvoiceRow(seedClientRow());
    dbExec(`UPDATE ip_invoice_amounts SET invoice_total = 888.88 WHERE invoice_id = ${invoiceId}`);

    /* Act */
    const response = await postWithoutToken(page, '/invoices/recalculate_all_invoices');

    /* Assert */
    expect(isRedirect(response), 'a token-less request must not reach the controller').toBe(false);
    expect(count('ip_invoice_amounts', `invoice_id = ${invoiceId} AND invoice_total = 888.88`)).toBe(1);
  });

  test('it recalculates quote amounts with a valid csrf token', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());
    dbExec(`UPDATE ip_quote_amounts SET quote_total = 777.77 WHERE quote_id = ${quoteId}`);

    /* Act */
    await postWithToken(page, '/quotes/recalculate_all_quotes');

    /* Assert */
    expect(count('ip_quote_amounts', `quote_id = ${quoteId} AND quote_total = 777.77`)).toBe(0);
    expect(count('ip_quote_amounts', `quote_id = ${quoteId} AND quote_total = 0`)).toBe(1);
  });

  test('it does not recalculate quote amounts without a csrf token', async ({ page }) => {
    /* Arrange */
    const quoteId = seedQuote(seedClientRow());
    dbExec(`UPDATE ip_quote_amounts SET quote_total = 666.66 WHERE quote_id = ${quoteId}`);

    /* Act */
    const response = await postWithoutToken(page, '/quotes/recalculate_all_quotes');

    /* Assert */
    expect(isRedirect(response), 'a token-less request must not reach the controller').toBe(false);
    expect(count('ip_quote_amounts', `quote_id = ${quoteId} AND quote_total = 666.66`)).toBe(1);
  });
});

test.describe('CSRF parity — sessions/passwordreset', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  /** A user with a live reset token: the hash is stored, the raw token is what the link carries. */
  function userWithResetToken() {
    const token = `parity-reset-token-${crypto.randomBytes(6).toString('hex')}`;
    const user = seedUser();
    dbExec(`UPDATE ip_users SET user_passwordreset_token = '${crypto.createHash('sha256').update(token).digest('hex')}', user_passwordreset_token_expiry = '${new Date(Date.now() + 3600000).toISOString().slice(0, 19).replace('T', ' ')}' WHERE user_id = ${user.id}`);

    return { token, userId: user.id };
  }

  const passwordOf = (id) => dbQuery(`SELECT user_password FROM ip_users WHERE user_id = ${id}`)[0].user_password;

  test('it changes a password via reset with a valid csrf token', async ({ page }) => {
    /* Arrange */
    const { token, userId } = userWithResetToken();
    const before = passwordOf(userId);
    await page.goto('/sessions/login');
    const csrf = await page.locator('input[name="_ip_csrf"]').first().inputValue();

    /* Act */
    const response = await page.request.post('/sessions/passwordreset', {
      form: { btn_new_password: '1', user_id: String(userId), token, new_password: 'BrandNewPass123', _ip_csrf: csrf }, maxRedirects: 0,
    });

    /* Assert */
    expect(isRedirect(response), 'a completed reset redirects to login').toBe(true);
    expect(passwordOf(userId), 'the password hash must change').not.toBe(before);
  });

  test('it does not change a password via reset without a csrf token', async ({ page }) => {
    /* Arrange */
    const { token, userId } = userWithResetToken();
    const before = passwordOf(userId);

    /* Act */
    const response = await page.request.post('/sessions/passwordreset', {
      form: { btn_new_password: '1', user_id: String(userId), token, new_password: 'BrandNewPass123' }, maxRedirects: 0,
    });

    /* Assert */
    expect(isRedirect(response), 'a token-less request must not reach the controller').toBe(false);
    expect(passwordOf(userId), 'the password hash must be untouched').toBe(before);
  });
});
