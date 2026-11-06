/**
 * Browser coverage for tests/Feature/SupplierInvoices/SupplierInvoicesControllerTest.php: every public
 * method of Supplier_invoices (index, view, form, suppliers, supplier_form, status, archive, restore,
 * payment, upload_attachment, download_document, download_attachment), driven through the rendered pages,
 * or through an authenticated request where a browser cannot send the input (a bogus status, a malformed
 * date, a missing file). The CSRF halves live in supplier-invoices-controller.csrf.spec.js.
 */

import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '../test.js';
import { dbInsert, dbQuery } from '../support/db.js';
import { countRows, invoiceRow, seedSupplier, seedSupplierInvoice } from '../support/supplier-invoices.js';

const ARCHIVE_DIR = path.resolve('uploads/archive');

// The controller sniffs the real content type, so the upload has to be a genuine image.
const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** Submit a form the way a user would, after switching off the browser's own `required` checks. */
async function submitWithoutBrowserValidation(page, buttonSelector) {
  await page.locator(buttonSelector).evaluate((button) => { button.form.noValidate = true; });
  await page.locator(buttonSelector).click();
}

const viewUrl = (id) => new RegExp(`/supplier_invoices/view/${id}$`);

async function fillPayment(page, values) {
  for (const [name, value] of Object.entries(values)) {
    await page.locator(`form[action*="supplier_invoices/payment"] [name="${name}"]`).fill(value);
  }
  await page.locator('form[action*="supplier_invoices/payment"] button[type="submit"]').click();
}

const INVALID_PAYMENTS = {
  overpayment: { payment_date: '2026-10-04', amount: '100.01' },
  'zero amount': { payment_date: '2026-10-04', amount: '0' },
  'negative amount': { payment_date: '2026-10-04', amount: '-5' },
  'non-numeric': { payment_date: '2026-10-04', amount: 'ten euros' },
  'empty amount': { payment_date: '2026-10-04', amount: '' },
  'missing date': { amount: '10' },
  'malformed date': { payment_date: '04/10/2026', amount: '10' },
};

test.describe('Supplier invoices — index', () => {
  test('it lists and filters supplier invoices', async ({ page }) => {
    /* Arrange */
    seedSupplierInvoice(seedSupplier('Acme Supplies'), 'ACME-001', '100.00');
    seedSupplierInvoice(seedSupplier('Other Supplies'), 'OTHER-001', '25.00');
    await page.goto('/supplier_invoices');
    await expect(page.locator('body')).toContainText('OTHER-001');

    /* Act */
    await page.locator('input[name="q"]').fill('Acme');
    await page.locator('form.form-inline button[type="submit"]').click();

    /* Assert */
    await expect(page.locator('body')).toContainText('ACME-001');
    await expect(page.locator('body')).not.toContainText('OTHER-001');
  });

  test('it hides archived invoices from the default list', async ({ page }) => {
    /* Arrange */
    const supplierId = seedSupplier('Archive Listing Supplier');
    seedSupplierInvoice(supplierId, 'LIVE-001', '10.00');
    seedSupplierInvoice(supplierId, 'GONE-001', '10.00', { archived_at: '2026-10-05 09:00:00' });

    /* Act */
    await page.goto('/supplier_invoices');

    /* Assert */
    await expect(page.locator('body')).toContainText('LIVE-001');
    await expect(page.locator('body')).not.toContainText('GONE-001');
  });

  test.describe('as a guest', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('it redirects unauthenticated users to login', async ({ page }) => {
      /* Act */
      await page.goto('/supplier_invoices');

      /* Assert */
      await expect(page).toHaveURL(/\/sessions\/login/);
      await expect(page.locator('body')).not.toContainText('Supplier');
    });
  });
});

test.describe('Supplier invoices — status, archive and payments', () => {
  test('it changes an invoice status and records the history', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Status Supplier'), 'STA-001', '80.00');
    await page.goto(`/supplier_invoices/view/${id}`);

    /* Act */
    await page.locator('select[name="status"]').selectOption('approved');
    await page.locator('input[name="comment"]').fill('Checked against the purchase order');
    await page.locator('form[action*="supplier_invoices/status"] button[type="submit"]').click();

    /* Assert */
    await expect(page).toHaveURL(viewUrl(id));
    expect(invoiceRow(id).status).toBe('approved');
    const [history] = dbQuery(`SELECT old_status, new_status, comment FROM ip_supplier_invoice_status_history WHERE supplier_invoice_id = ${id}`);
    expect(history).toEqual({ old_status: 'received', new_status: 'approved', comment: 'Checked against the purchase order' });
    await expect(page.locator('body')).toContainText('Checked against the purchase order');
  });

  test('it rejects an unknown status without changing the invoice', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Bogus Status Supplier'), 'STA-002', '80.00');

    /* Act */
    const response = await page.request.post(`/supplier_invoices/status/${id}`, { form: { status: 'bogus' }, maxRedirects: 0 });

    /* Assert */
    expect(response.status()).toBe(303);
    expect(response.headers().location).toMatch(viewUrl(id));
    expect(invoiceRow(id).status).toBe('received');
    expect(countRows('ip_supplier_invoice_status_history', `supplier_invoice_id = ${id}`)).toBe(0);
  });

  test('it archives and restores an invoice without deleting it', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Archive Supplier'), 'ARCH-001', '50.00');
    await page.goto(`/supplier_invoices/view/${id}`);

    /* Act - archive */
    await page.locator('form[action*="supplier_invoices/archive"] button[type="submit"]').click();

    /* Assert - archive */
    await expect(page).toHaveURL(viewUrl(id));
    expect(invoiceRow(id).archived_at).not.toBeNull();
    await expect(page.locator('form[action*="supplier_invoices/restore"]')).toBeVisible();

    /* Act - restore */
    await page.locator('form[action*="supplier_invoices/restore"] button[type="submit"]').click();

    /* Assert - restore */
    await expect(page).toHaveURL(viewUrl(id));
    expect(invoiceRow(id).archived_at).toBeNull();
    expect(countRows('ip_supplier_invoices', `supplier_invoice_id = ${id}`)).toBe(1);
  });

  test('it records a payment and marks a fully paid invoice', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Payment Supplier'), 'PAY-001', '100.00');
    await page.goto(`/supplier_invoices/view/${id}`);

    /* Act */
    await fillPayment(page, { payment_date: '2026-10-04', amount: '100.00', payment_method: 'Bank transfer', reference: 'PAYMENT-001' });

    /* Assert */
    await expect(page).toHaveURL(viewUrl(id));
    expect(Number(invoiceRow(id).amount_paid)).toBe(100);
    expect(invoiceRow(id).status).toBe('paid');
    expect(countRows('ip_supplier_invoice_payments', `supplier_invoice_id = ${id} AND reference = 'PAYMENT-001'`)).toBe(1);
    await expect(page.locator('body')).toContainText('PAYMENT-001');
  });

  test('it records a partial payment without settling the invoice', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Partial Supplier'), 'PART-001', '100.00');
    await page.goto(`/supplier_invoices/view/${id}`);

    /* Act: a comma decimal, as typed in many locales */
    await fillPayment(page, { payment_date: '2026-10-04', amount: '40,50' });

    /* Assert: normalised, the balance moves and the status does not */
    await expect(page).toHaveURL(viewUrl(id));
    expect(Number(invoiceRow(id).amount_paid)).toBe(40.5);
    expect(invoiceRow(id).status).toBe('received');
    expect(countRows('ip_supplier_invoice_payments', `supplier_invoice_id = ${id} AND amount = 40.5`)).toBe(1);
    expect(countRows('ip_supplier_invoice_status_history', `supplier_invoice_id = ${id}`)).toBe(0);
  });

  test('it settles the invoice when partial payments add up to the total', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Two Step Supplier'), 'PART-002', '100.00');
    await page.goto(`/supplier_invoices/view/${id}`);

    /* Act */
    await fillPayment(page, { payment_date: '2026-10-04', amount: '60' });
    await expect(page).toHaveURL(viewUrl(id));
    await fillPayment(page, { payment_date: '2026-10-05', amount: '40' });
    await expect(page).toHaveURL(viewUrl(id));

    /* Assert */
    expect(Number(invoiceRow(id).amount_paid)).toBe(100);
    expect(invoiceRow(id).status).toBe('paid');
    expect(countRows('ip_supplier_invoice_payments', `supplier_invoice_id = ${id}`)).toBe(2);
    expect(countRows('ip_supplier_invoice_status_history', `supplier_invoice_id = ${id} AND new_status = 'paid'`)).toBe(1);
  });

  for (const [label, post] of Object.entries(INVALID_PAYMENTS)) {
    test(`it refuses an invalid payment (${label}) and leaves the books untouched`, async ({ page }) => {
      /* Arrange */
      const id = seedSupplierInvoice(seedSupplier('Strict Supplier'), 'BAD-001', '100.00');

      /* Act */
      const response = await page.request.post(`/supplier_invoices/payment/${id}`, { form: post, maxRedirects: 0 });

      /* Assert */
      expect(response.status()).toBe(303);
      expect(response.headers().location).toMatch(viewUrl(id));
      expect(countRows('ip_supplier_invoice_payments', `supplier_invoice_id = ${id}`)).toBe(0);
      expect(Number(invoiceRow(id).amount_paid)).toBe(0);
      expect(invoiceRow(id).status).toBe('received');
    });
  }

  test('it returns 404 for a payment on an unknown invoice', async ({ page }) => {
    /* Act */
    const response = await page.request.post('/supplier_invoices/payment/999999', { form: { payment_date: '2026-10-04', amount: '10' }, maxRedirects: 0 });

    /* Assert */
    expect(response.status()).toBe(404);
    expect(countRows('ip_supplier_invoice_payments')).toBe(0);
  });
});

test.describe('Supplier invoices — form', () => {
  /** Open the invoice form and fill the fields every valid invoice needs. */
  async function openFilledForm(page, supplierId, number) {
    await page.goto('/supplier_invoices/form');
    await page.locator('select[name="supplier_id"]').selectOption(String(supplierId));
    await page.locator('input[name="supplier_invoice_number"]').fill(number);
    await page.locator('input[name="supplier_invoice_date"]').fill('2026-10-04');
  }

  test('it creates a supplier invoice with its line items', async ({ page }) => {
    /* Arrange */
    const supplierId = seedSupplier('Form Supplier');
    await openFilledForm(page, supplierId, 'FORM-001');
    const names = page.locator('input[name="item_name[]"]');
    const quantities = page.locator('input[name="quantity[]"]');
    const prices = page.locator('input[name="unit_price[]"]');
    await names.nth(0).fill('Consulting');
    await quantities.nth(0).fill('2');
    await prices.nth(0).fill('40');
    await names.nth(1).fill('Travel');
    await quantities.nth(1).fill('1');
    await prices.nth(1).fill('41');

    /* Act */
    await page.locator('button[name="btn_submit"]').click();

    /* Assert */
    const [saved] = dbQuery("SELECT supplier_invoice_id, supplier_id FROM ip_supplier_invoices WHERE supplier_invoice_number = 'FORM-001'");
    expect(saved, 'the invoice must be persisted').toBeTruthy();
    await expect(page).toHaveURL(viewUrl(saved.supplier_invoice_id));
    expect(Number(saved.supplier_id)).toBe(supplierId);
    expect(countRows('ip_supplier_invoice_items', `supplier_invoice_id = ${saved.supplier_invoice_id} AND item_name = 'Consulting'`)).toBe(1);
    expect(countRows('ip_supplier_invoice_items', `supplier_invoice_id = ${saved.supplier_invoice_id} AND item_name = 'Travel'`)).toBe(1);
    await expect(page.locator('body')).toContainText('Consulting');
  });

  const INCOMPLETE_FORMS = {
    'no supplier': async (page) => page.locator('select[name="supplier_id"]').selectOption(''),
    'unknown supplier': async (page) => page.locator('select[name="supplier_id"]').evaluate((select) => {
      select.add(new Option('Ghost', '999999'));
      select.value = '999999';
    }),
    'no invoice number': async (page) => page.locator('input[name="supplier_invoice_number"]').fill(''),
    'no invoice date': async (page) => page.locator('input[name="supplier_invoice_date"]').fill(''),
  };

  for (const [label, breakIt] of Object.entries(INCOMPLETE_FORMS)) {
    test(`it rejects an incomplete invoice form (${label}) and saves nothing`, async ({ page }) => {
      /* Arrange */
      await openFilledForm(page, seedSupplier('Validating Supplier'), 'INVALID-001');
      await breakIt(page);

      /* Act */
      await submitWithoutBrowserValidation(page, 'button[name="btn_submit"]');

      /* Assert: the form is shown again with errors, and no invoice row exists */
      await expect(page.locator('.alert-danger li').first()).toBeVisible();
      await expect(page).toHaveURL(/\/supplier_invoices\/form/);
      expect(countRows('ip_supplier_invoices')).toBe(0);
    });
  }

  test('it rejects a duplicate invoice number for the same supplier but allows it for another', async ({ page }) => {
    /* Arrange */
    const supplierA = seedSupplier('Supplier A');
    const supplierB = seedSupplier('Supplier B');
    seedSupplierInvoice(supplierA, 'DUP-001', '10.00');

    /* Act: the same number for the same supplier */
    await openFilledForm(page, supplierA, 'DUP-001');
    await page.locator('button[name="btn_submit"]').click();

    /* Assert: the user is told why, in a validation message */
    await expect(page.locator('.alert-danger li').filter({ hasText: 'already exists for the selected supplier' })).toBeVisible();
    expect(countRows('ip_supplier_invoices', `supplier_id = ${supplierA}`)).toBe(1);

    /* Act: the same number for another supplier */
    await openFilledForm(page, supplierB, 'DUP-001');
    await page.locator('button[name="btn_submit"]').click();

    /* Assert */
    await expect(page).toHaveURL(/\/supplier_invoices\/view\/\d+$/);
    expect(countRows('ip_supplier_invoices', `supplier_id = ${supplierB}`)).toBe(1);
  });

  test('it edits an existing invoice in place', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Edit Supplier'), 'EDIT-001', '50.00');
    await page.goto(`/supplier_invoices/form/${id}`);

    /* Act */
    await page.locator('textarea[name="notes"]').fill('amended');
    await page.locator('input[name="total"]').fill('75.00');
    await page.locator('button[name="btn_submit"]').click();

    /* Assert: the same row is updated and no second invoice is created */
    await expect(page).toHaveURL(viewUrl(id));
    expect(invoiceRow(id).notes).toBe('amended');
    expect(Number(invoiceRow(id).total)).toBe(75);
    expect(countRows('ip_supplier_invoices')).toBe(1);
  });

  test('it returns 404 when editing an unknown invoice', async ({ page }) => {
    /* Act */
    const response = await page.request.get('/supplier_invoices/form/999999');

    /* Assert */
    expect(response.status()).toBe(404);
  });

  test('it returns to the list when the invoice form is cancelled', async ({ page }) => {
    /* Arrange */
    await page.goto('/supplier_invoices/form');

    /* Act */
    await submitWithoutBrowserValidation(page, 'button[name="btn_cancel"]');

    /* Assert */
    await expect(page).toHaveURL(/\/supplier_invoices$/);
    expect(countRows('ip_supplier_invoices')).toBe(0);
  });
});

test.describe('Supplier invoices — suppliers', () => {
  test('it lists suppliers', async ({ page }) => {
    /* Arrange */
    seedSupplier('Listed Supplier One');
    seedSupplier('Listed Supplier Two');

    /* Act */
    await page.goto('/supplier_invoices/suppliers');

    /* Assert */
    await expect(page.locator('body')).toContainText('Listed Supplier One');
    await expect(page.locator('body')).toContainText('Listed Supplier Two');
  });

  test('it creates and then updates a supplier', async ({ page }) => {
    /* Arrange */
    await page.goto('/supplier_invoices/supplier_form');
    await page.locator('input[name="supplier_name"]').fill('Acme Components');
    await page.locator('input[name="supplier_vat_id"]').fill('NL123456789B01');
    await page.locator('input[name="supplier_email"]').fill('ap@acme.test');

    /* Act */
    await page.locator('button[name="btn_submit"]').click();

    /* Assert: created */
    await expect(page).toHaveURL(/\/supplier_invoices\/suppliers$/);
    const [row] = dbQuery("SELECT supplier_id, supplier_vat_id FROM ip_suppliers WHERE supplier_name = 'Acme Components'");
    expect(row.supplier_vat_id).toBe('NL123456789B01');

    /* Act: update and deactivate */
    await page.goto(`/supplier_invoices/supplier_form/${row.supplier_id}`);
    await page.locator('input[name="supplier_name"]').fill('Acme Components BV');
    await page.locator('input[name="supplier_active"]').uncheck();
    await page.locator('button[name="btn_submit"]').click();

    /* Assert: updated in place and deactivated */
    await expect(page).toHaveURL(/\/supplier_invoices\/suppliers$/);
    const [updated] = dbQuery(`SELECT supplier_name, supplier_active FROM ip_suppliers WHERE supplier_id = ${row.supplier_id}`);
    expect(updated.supplier_name).toBe('Acme Components BV');
    expect(Number(updated.supplier_active)).toBe(0);
    expect(countRows('ip_suppliers')).toBe(1);
  });

  test('it rejects a supplier without a name', async ({ page }) => {
    /* Arrange */
    await page.goto('/supplier_invoices/supplier_form');
    await page.locator('input[name="supplier_name"]').fill('   ');
    await page.locator('input[name="supplier_email"]').fill('x@y.test');

    /* Act */
    await submitWithoutBrowserValidation(page, 'button[name="btn_submit"]');

    /* Assert */
    await expect(page.locator('.alert-danger li').first()).toBeVisible();
    expect(countRows('ip_suppliers')).toBe(0);
  });

  test('it returns 404 for an unknown supplier', async ({ page }) => {
    /* Act */
    const response = await page.request.get('/supplier_invoices/supplier_form/999999');

    /* Assert */
    expect(response.status()).toBe(404);
  });
});

test.describe('Supplier invoices — documents', () => {
  test('it registers no attachment when the upload carries no file', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Attachment Supplier'), 'ATT-001', '40.00');

    /* Act */
    const response = await page.request.post(`/supplier_invoices/upload_attachment/${id}`, { maxRedirects: 0 });

    /* Assert */
    expect(response.status()).toBe(303);
    expect(response.headers().location).toMatch(viewUrl(id));
    expect(countRows('ip_supplier_invoice_attachments', `supplier_invoice_id = ${id}`)).toBe(0);
  });

  test('it attaches an uploaded file to the invoice', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Upload Supplier'), 'ATT-002', '40.00');
    await page.goto(`/supplier_invoices/view/${id}`);

    /* Act */
    await page.locator('input[name="attachment"]').setInputFiles({ name: 'scan.png', mimeType: 'image/png', buffer: Buffer.from(ONE_PIXEL_PNG, 'base64') });
    await page.locator('form[action*="upload_attachment"] button[type="submit"]').click();

    /* Assert */
    await expect(page).toHaveURL(viewUrl(id));
    const [attachment] = dbQuery(`SELECT file_name, storage_path FROM ip_supplier_invoice_attachments WHERE supplier_invoice_id = ${id}`);
    expect(attachment.file_name).toBe('scan.png');
    await expect(page.locator('body')).toContainText('scan.png');
    fs.rmSync(path.join(ARCHIVE_DIR, attachment.storage_path), { force: true });
  });

  test('it streams a stored attachment with safe headers and exact bytes', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('Download Supplier'), 'DL-001', '10.00');
    const relative = `supplier-invoices/${id}/test-download-${Date.now()}.pdf`;
    const absolute = path.join(ARCHIVE_DIR, relative);
    fs.mkdirSync(path.dirname(absolute), { recursive: true });
    fs.writeFileSync(absolute, '%PDF-1.4 attachment-bytes');
    const attachmentId = dbInsert('ip_supplier_invoice_attachments', {
      supplier_invoice_id: id, file_name: 'scan.pdf', storage_path: relative, mime_type: 'application/pdf', file_size: 25, sha256: `${'a'.repeat(64)}`, created_at: '2026-10-04 12:00:00',
    });

    try {
      /* Act */
      const response = await page.request.get(`/supplier_invoices/download_attachment/${attachmentId}`);

      /* Assert */
      expect(response.status()).toBe(200);
      expect(await response.text()).toBe('%PDF-1.4 attachment-bytes');
      expect(response.headers()['content-type']).toContain('application/pdf');
      expect(response.headers()['content-disposition']).toBe('attachment; filename="scan.pdf"');
      expect(response.headers()['x-content-type-options']).toBe('nosniff');
      expect(response.headers()['cache-control']).toContain('no-store');
    } finally {
      fs.rmSync(absolute, { force: true });
    }
  });

  test('it refuses an attachment whose stored path escapes the archive', async ({ page }) => {
    /* Arrange: a tampered row pointing at the application config */
    const id = seedSupplierInvoice(seedSupplier('Tampered Supplier'), 'DL-002', '10.00');
    const attachmentId = dbInsert('ip_supplier_invoice_attachments', {
      supplier_invoice_id: id, file_name: 'steal.txt', storage_path: '../../ipconfig.php', mime_type: 'text/plain', file_size: 1, sha256: `${'b'.repeat(64)}`, created_at: '2026-10-04 12:00:00',
    });

    /* Act */
    const response = await page.request.get(`/supplier_invoices/download_attachment/${attachmentId}`);

    /* Assert */
    expect(response.status()).toBe(404);
    expect(await response.text()).not.toContain('ENCRYPTION_KEY');
  });

  test('it returns 404 for an unknown attachment and for an invoice without a document', async ({ page }) => {
    /* Arrange */
    const id = seedSupplierInvoice(seedSupplier('No Document Supplier'), 'DL-003', '10.00');

    /* Act */
    const unknownAttachment = await page.request.get('/supplier_invoices/download_attachment/999999');
    const noDocument = await page.request.get(`/supplier_invoices/download_document/${id}`);
    const unknownInvoice = await page.request.get('/supplier_invoices/download_document/999999');

    /* Assert */
    expect(unknownAttachment.status()).toBe(404);
    expect(noDocument.status()).toBe(404);
    expect(unknownInvoice.status()).toBe(404);
  });
});
