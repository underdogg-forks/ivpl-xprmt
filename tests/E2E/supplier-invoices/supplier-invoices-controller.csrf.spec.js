/**
 * The CSRF-enabled half of tests/Feature/SupplierInvoices/SupplierInvoicesControllerTest.php (the tests
 * that call enableCsrfProtection()): the state-changing routes refuse a request without a token and accept the
 * token the rendered form carries. Runs in the chromium-csrf project, against the server with CSRF protection on.
 */

import { test, expect } from '../test.js';
import { dbQuery } from '../support/db.js';
import { countRows, invoiceRow, seedSupplier, seedSupplierInvoice } from '../support/supplier-invoices.js';

test.describe('Supplier invoices — CSRF', () => {
  test('it refuses a status change without a csrf token', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('No Token Supplier'), 'STA-003', '80.00');

    /* Act */
    const response = await page.request.post(`/supplier_invoices/status/${id}`, { form: { status: 'approved' }, maxRedirects: 0 });

    /* Assert: it never reaches the controller */
    expect(response.status()).toBe(403);
    expect(invoiceRow(id).status).toBe('received');
    expect(countRows('ip_supplier_invoice_status_history', `supplier_invoice_id = ${id}`)).toBe(0);
  });

  test('it refuses an attachment upload without a csrf token', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Attachment No Token'), 'ATT-002', '40.00');

    /* Act */
    const response = await page.request.post(`/supplier_invoices/upload_attachment/${id}`, { maxRedirects: 0 });

    /* Assert */
    expect(response.status()).toBe(403);
    expect(countRows('ip_supplier_invoice_attachments', `supplier_invoice_id = ${id}`)).toBe(0);
  });

  test('it refuses a payment without a csrf token', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Payment No Token'), 'PAY-002', '100.00');

    /* Act */
    const response = await page.request.post(`/supplier_invoices/payment/${id}`, { form: { payment_date: '2026-10-04', amount: '100' }, maxRedirects: 0 });

    /* Assert */
    expect(response.status()).toBe(403);
    expect(countRows('ip_supplier_invoice_payments', `supplier_invoice_id = ${id}`)).toBe(0);
    expect(Number(invoiceRow(id).amount_paid)).toBe(0);
  });

  test('it refuses to archive an invoice without a csrf token', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Archive No Token'), 'ARCH-002', '10.00');

    /* Act */
    const response = await page.request.post(`/supplier_invoices/archive/${id}`, { maxRedirects: 0 });

    /* Assert */
    expect(response.status()).toBe(403);
    expect(invoiceRow(id).archived_at).toBeNull();
  });

  test('it changes a status with the token the form carries', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Status Token Supplier'), 'STA-004', '80.00');
    await page.goto(`/supplier_invoices/view/${id}`);

    /* Act */
    await page.locator('select[name="status"]').selectOption('approved');
    await page.locator('form[action*="supplier_invoices/status"] button[type="submit"]').click();

    /* Assert */
    await expect(page).toHaveURL(new RegExp(`/supplier_invoices/view/${id}$`));
    expect(invoiceRow(id).status).toBe('approved');
  });

  test('it records a payment with the token the form carries', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Payment Token Supplier'), 'PAY-003', '100.00');
    await page.goto(`/supplier_invoices/view/${id}`);

    /* Act */
    await page.locator('form[action*="supplier_invoices/payment"] [name="amount"]').fill('100');
    await page.locator('form[action*="supplier_invoices/payment"] button[type="submit"]').click();

    /* Assert */
    await expect(page).toHaveURL(new RegExp(`/supplier_invoices/view/${id}$`));
    expect(invoiceRow(id).status).toBe('paid');
    expect(countRows('ip_supplier_invoice_payments', `supplier_invoice_id = ${id}`)).toBe(1);
  });

  test('it creates a supplier invoice and a supplier with the tokens the forms carry', async ({ page }) => {
    /* Arrange */
    await page.goto('/supplier_invoices/supplier_form');
    await page.locator('input[name="supplier_name"]').fill('Token Supplier');

    /* Act: the supplier */
    await page.locator('button[name="btn_submit"]').click();
    await expect(page).toHaveURL(/\/supplier_invoices\/suppliers$/);
    const [supplier] = dbQuery("SELECT supplier_id FROM ip_suppliers WHERE supplier_name = 'Token Supplier'");
    await page.goto('/supplier_invoices/form');
    await page.locator('select[name="supplier_id"]').selectOption(String(supplier.supplier_id));
    await page.locator('input[name="supplier_invoice_number"]').fill('TOKEN-001');
    await page.locator('input[name="supplier_invoice_date"]').fill('2026-10-04');
    await page.locator('button[name="btn_submit"]').click();

    /* Assert */
    await expect(page).toHaveURL(/\/supplier_invoices\/view\/\d+$/);
    expect(countRows('ip_supplier_invoices', "supplier_invoice_number = 'TOKEN-001'")).toBe(1);
  });
});
