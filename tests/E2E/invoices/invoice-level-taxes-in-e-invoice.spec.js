/**
 * Browser coverage for the invoice-level tax guard on e-invoices. Mirrors
 * tests/Feature/EInvoicing/InvoiceLevelTaxesInEInvoiceTest.php: an invoice-level tax changes the PDF total but is left
 * out of the e-invoice XML, so the invoice view warns and invoices/generate_xml refuses.
 */

import { test, expect } from '../test.js';
import { dbExec, dbInsert } from '../support/db.js';
import { seedInvoiceRow } from '../support/invoices.js';
import { seedClientRow } from '../support/quotes.js';

const WARNING = 'Invoice-level taxes are not supported in e-invoices';
const today = () => new Date().toISOString().slice(0, 10);

/** The PHPUnit setUp(): e-invoicing on, and the issuing user (id 1) has a tax code. */
function enableEinvoicing() {
  dbExec("INSERT IGNORE INTO ip_settings (setting_key, setting_value) VALUES ('einvoicing', '1')");
  dbExec("UPDATE ip_settings SET setting_value = '1' WHERE setting_key = 'einvoicing'");
  dbExec("UPDATE ip_users SET user_tax_code = 'TAX-123456' WHERE user_id = 1");
}

/** The PHPUnit einvoice() builder: a client, one 100.00 item and, optionally, one invoice-level tax row. */
function einvoice({ withInvoiceTax, clientUsesEinvoicing = true }) {
  const clientId = seedClientRow(clientUsesEinvoicing ? { client_einvoicing_active: 1, client_einvoicing_version: 'Facturxv10' } : {});
  const invoiceId = seedInvoiceRow(clientId);
  const itemId = dbInsert('ip_invoice_items', {
    invoice_id: invoiceId, item_name: 'Test Item', item_description: '', item_quantity: '1', item_price: '100.00', item_order: 1, item_date_added: today(),
  });
  dbInsert('ip_invoice_item_amounts', { item_id: itemId, item_subtotal: '100.00', item_tax_total: '0.00', item_discount: '0.00', item_total: '100.00' });

  if (withInvoiceTax) {
    const taxRateId = dbInsert('ip_tax_rates', { tax_rate_name: 'VAT', tax_rate_percent: 19 });
    dbInsert('ip_invoice_tax_rates', { invoice_id: invoiceId, tax_rate_id: taxRateId, invoice_tax_rate_amount: 19, include_item_tax: 0 });
  }

  return invoiceId;
}

test.describe('Invoices — invoice-level taxes in an e-invoice', () => {
  test.beforeEach(() => enableEinvoicing());

  test('it shows warning in invoice view when taxes and einvoicing present', async ({ page }) => {
    /* Arrange */
    const invoiceId = einvoice({ withInvoiceTax: true });

    /* Act */
    const response = await page.request.get(`/invoices/view/${invoiceId}`);

    /* Assert */
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain(WARNING);
  });

  test('it does not show warning when no invoice level taxes present', async ({ page }) => {
    /* Arrange: positive control, the same e-invoice setup without the tax */
    const invoiceId = einvoice({ withInvoiceTax: false });

    /* Act */
    const response = await page.request.get(`/invoices/view/${invoiceId}`);

    /* Assert */
    expect(response.status()).toBe(200);
    expect(await response.text()).not.toContain(WARNING);
  });

  test('it does not show warning when einvoicing inactive', async ({ page }) => {
    /* Arrange: the invoice has the tax, but the client does not use e-invoicing */
    const invoiceId = einvoice({ withInvoiceTax: true, clientUsesEinvoicing: false });

    /* Act */
    const response = await page.request.get(`/invoices/view/${invoiceId}`);

    /* Assert */
    expect(response.status()).toBe(200);
    expect(await response.text()).not.toContain(WARNING);
  });

  test('it does not show warning when the issuing user cannot send e invoices', async ({ page }) => {
    /* Arrange: the client uses e-invoicing, but the user issuing the invoice has no tax code, so no e-invoice can be made anyway */
    const invoiceId = einvoice({ withInvoiceTax: true });
    dbExec("UPDATE ip_users SET user_tax_code = '' WHERE user_id = 1");

    /* Act */
    const response = await page.request.get(`/invoices/view/${invoiceId}`);

    /* Assert */
    expect(response.status()).toBe(200);
    expect(await response.text()).not.toContain(WARNING);
  });

  test('it rejects xml generation when invoice level taxes present', async ({ page }) => {
    /* Arrange */
    const invoiceId = einvoice({ withInvoiceTax: true });

    /* Act */
    const response = await page.request.get(`/invoices/generate_xml/${invoiceId}`);

    /* Assert */
    expect(response.status()).toBe(400);
    const body = await response.text();
    expect(body).toContain(WARNING);
    expect(body).toContain('Please remove all invoice-level taxes from this invoice');
  });

  test('it does not reject xml generation for an invoice without invoice level taxes', async ({ page }) => {
    /* Arrange: positive control, the route is reachable for this setup and is only refused because of the tax */
    const invoiceId = einvoice({ withInvoiceTax: false });

    /* Act */
    const response = await page.request.get(`/invoices/generate_xml/${invoiceId}`);

    /* Assert */
    expect(response.status()).not.toBe(400);
    expect(response.status()).not.toBe(404);
    expect(await response.text()).not.toContain(WARNING);
  });
});
