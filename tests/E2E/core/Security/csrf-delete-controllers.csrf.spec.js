/**
 * Browser-side mirror of the "delete with CSRF protection on" regression (#1694) that every CRUD controller test in
 * tests/Feature repeats: with CSRF on, a delete carrying the token a page rendered still deletes, and a token-less
 * delete never reaches the controller. Runs in the chromium-csrf project; each controller's seed and delete route is
 * listed once below.
 */

import { test, expect } from '../../test.js';
import { dbInsert } from '../../support/db.js';
import { isRedirect, postWithToken, postWithoutToken } from '../../support/csrf.js';
import { seedInvoiceRow } from '../../support/invoices.js';
import { count, seedClientRow } from '../../support/quotes.js';

const today = () => new Date().toISOString().slice(0, 10);

const CONTROLLERS = {
  'a client': { table: 'ip_clients', key: 'client_id', route: '/clients/delete', seed: () => seedClientRow() },
  'a project': { table: 'ip_projects', key: 'project_id', route: '/projects/delete', seed: () => dbInsert('ip_projects', { project_name: 'Csrf Project', client_id: seedClientRow() }) },
  'a task': { table: 'ip_tasks', key: 'task_id', route: '/tasks/delete', seed: () => dbInsert('ip_tasks', { task_name: 'Csrf Task', task_price: '10.00', task_status: 1, project_id: 0 }) },
  'a unit': { table: 'ip_units', key: 'unit_id', route: '/units/delete', seed: () => dbInsert('ip_units', { unit_name: 'Csrf Unit', unit_name_plrl: 'Csrf Units' }) },
  'a product': { table: 'ip_products', key: 'product_id', route: '/products/delete', seed: () => dbInsert('ip_products', { product_name: 'Csrf Product', product_price: '10.00' }) },
  'a family': { table: 'ip_families', key: 'family_id', route: '/families/delete', seed: () => dbInsert('ip_families', { family_name: 'Csrf Family' }) },
  'a payment method': { table: 'ip_payment_methods', key: 'payment_method_id', route: '/payment_methods/delete', seed: () => dbInsert('ip_payment_methods', { payment_method_name: 'Csrf Method' }) },
  'an invoice group': { table: 'ip_invoice_groups', key: 'invoice_group_id', route: '/invoice_groups/delete', seed: () => dbInsert('ip_invoice_groups', { invoice_group_name: 'Csrf Group', invoice_group_identifier_format: '{{{id}}}', invoice_group_next_id: 1, invoice_group_left_pad: 0 }) },
  'a draft invoice': { table: 'ip_invoices', key: 'invoice_id', route: '/invoices/delete', seed: () => seedInvoiceRow(seedClientRow(), { invoice_status_id: 1, invoice_number: 'INV-CSRF' }) },
  'a recurring schedule': {
    table: 'ip_invoices_recurring', key: 'invoice_recurring_id', route: '/invoices/recurring/delete',
    seed: () => dbInsert('ip_invoices_recurring', {
      invoice_id: seedInvoiceRow(seedClientRow()), recur_start_date: today(), recur_end_date: '0000-00-00', recur_frequency: '1M', recur_next_date: today(),
    }),
  },
};

for (const [what, { table, key, route, seed }] of Object.entries(CONTROLLERS)) {
  test.describe(`CSRF delete — ${what}`, () => {
    test(`it still deletes ${what} when csrf protection is on and the token is valid`, async ({ page }) => {
      /* Arrange */
      const id = seed();

      /* Act */
      const response = await postWithToken(page, `${route}/${id}`);

      /* Assert */
      expect(isRedirect(response), 'a valid-token delete redirects').toBe(true);
      expect(count(table, `${key} = ${id}`)).toBe(0);
    });

    test(`it does not delete ${what} when the csrf token is missing`, async ({ page }) => {
      /* Arrange */
      const id = seed();

      /* Act */
      const response = await postWithoutToken(page, `${route}/${id}`);

      /* Assert */
      expect(isRedirect(response), 'a token-less delete must not reach the controller').toBe(false);
      expect(count(table, `${key} = ${id}`)).toBe(1);
    });
  });
}

test.describe('CSRF — unassigning a client from a user', () => {
  const seedAssignment = () => {
    const userId = dbInsert('ip_users', { user_type: 2, user_name: 'Csrf User', user_email: `csrf-${Date.now()}@test.local`, user_password: 'x', user_psalt: 'e2e', user_language: 'system', user_active: 1 });

    return dbInsert('ip_user_clients', { user_id: userId, client_id: seedClientRow() });
  };

  test('it still unassigns when csrf protection is on and the token is valid', async ({ page }) => {
    /* Arrange */
    const id = seedAssignment();

    /* Act */
    const response = await postWithToken(page, `/user_clients/delete/${id}`);

    /* Assert */
    expect(isRedirect(response), 'a valid-token unassign redirects').toBe(true);
    expect(count('ip_user_clients', `user_client_id = ${id}`)).toBe(0);
  });

  test('it does not unassign when the csrf token is missing', async ({ page }) => {
    /* Arrange */
    const id = seedAssignment();

    /* Act */
    const response = await postWithoutToken(page, `/user_clients/delete/${id}`);

    /* Assert */
    expect(isRedirect(response), 'a token-less unassign must not reach the controller').toBe(false);
    expect(count('ip_user_clients', `user_client_id = ${id}`)).toBe(1);
  });
});

test.describe('CSRF — invoice tax rates', () => {
  test('it does not remove an invoice tax rate when the csrf token is missing', async ({ page }) => {
    /* Arrange */
    const invoiceId = seedInvoiceRow(seedClientRow());
    const rateId = dbInsert('ip_invoice_tax_rates', { invoice_id: invoiceId, tax_rate_id: 1, include_item_tax: 0, invoice_tax_rate_amount: '0.00' });

    /* Act */
    const response = await postWithoutToken(page, `/invoices/delete_invoice_tax/${invoiceId}/${rateId}`);

    /* Assert */
    expect(isRedirect(response), 'a token-less request must not reach the controller').toBe(false);
    expect(count('ip_invoice_tax_rates', `invoice_tax_rate_id = ${rateId}`)).toBe(1);
  });
});
