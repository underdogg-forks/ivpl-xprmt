/**
 * Browser coverage for editing a payment. Mirrors tests/Feature/Payments/PaymentEditReopensInvoiceTest.php:
 * Mdl_payments::save() used to move an invoice forward to Paid but never back, so editing a 100.00 payment of a
 * 100.00 invoice down to 40.00 left it Paid with a 60.00 balance.
 */

import { test, expect } from '../test.js';
import { dbExec, dbInsert, dbQuery } from '../support/db.js';
import { postForm } from '../support/http.js';
import { seedInvoiceRow } from '../support/invoices.js';
import { seedClientRow } from '../support/quotes.js';

const SENT = 2;
const VIEWED = 3;
const PAID = 4;
const today = () => new Date().toISOString().slice(0, 10);

/** The PHPUnit invoiceWithItem(): an invoice of `amount` with one item, so recalculation has something to sum. */
function invoiceWithItem(amount, status, number = '') {
  const invoiceId = seedInvoiceRow(seedClientRow(), { invoice_status_id: status, ...(number ? { invoice_number: number } : {}) });
  const itemId = dbInsert('ip_invoice_items', {
    invoice_id: invoiceId, item_name: 'Line', item_description: '', item_quantity: '1', item_price: amount, item_order: 1, item_date_added: today(),
  });
  dbInsert('ip_invoice_item_amounts', { item_id: itemId, item_subtotal: amount, item_tax_total: '0.00', item_discount: '0.00', item_total: amount });
  dbExec(`UPDATE ip_invoice_amounts SET invoice_total = '${amount}', invoice_balance = '${amount}' WHERE invoice_id = ${invoiceId}`);

  return invoiceId;
}

const seedPayment = (invoiceId, amount) => dbInsert('ip_payments', {
  invoice_id: invoiceId, payment_method_id: 1, payment_date: today(), payment_amount: amount, payment_note: 'seed',
});

/** A Paid invoice with a single payment covering it. */
function paidInvoice(amount, number = '') {
  const invoiceId = invoiceWithItem(amount, PAID, number);
  const paymentId = seedPayment(invoiceId, amount);
  dbExec(`UPDATE ip_invoice_amounts SET invoice_paid = '${amount}', invoice_balance = '0.00' WHERE invoice_id = ${invoiceId}`);

  return [invoiceId, paymentId];
}

const editPayment = (page, invoiceId, paymentId, amount, note = '') => postForm(page, `/payments/form/${paymentId}`, {
  payment_id: String(paymentId),
  invoice_id: String(invoiceId),
  payment_method_id: '1',
  payment_amount: amount,
  payment_date: today(),
  payment_note: note,
  btn_submit: '1',
});

const statusOf = (invoiceId) => Number(dbQuery(`SELECT invoice_status_id FROM ip_invoices WHERE invoice_id = ${invoiceId}`)[0].invoice_status_id);

test.describe('Payments — editing a payment reopens the invoice', () => {
  test('it reopens a paid invoice when the payment is edited to a partial amount', async ({ page }) => {
    /* Arrange: a 100.00 invoice, Paid by a single 100.00 payment */
    const [invoiceId, paymentId] = paidInvoice('100.00');

    /* Act: the bookkeeper corrects the payment to 40.00 */
    const response = await editPayment(page, invoiceId, paymentId, '40.00');

    /* Assert */
    expect([301, 302, 303]).toContain(response.status());
    expect(dbQuery(`SELECT payment_amount FROM ip_payments WHERE payment_id = ${paymentId}`)).toEqual([{ payment_amount: '40.00' }]);
    expect(dbQuery(`SELECT invoice_paid, invoice_balance FROM ip_invoice_amounts WHERE invoice_id = ${invoiceId}`)).toEqual([{ invoice_paid: '40.00', invoice_balance: '60.00' }]);
    expect(statusOf(invoiceId)).toBe(SENT);
  });

  test('it offers the reopened invoice in the payment form again', async ({ page }) => {
    /* Arrange */
    const [invoiceId, paymentId] = paidInvoice('100.00', 'REOPEN-LIST');
    await editPayment(page, invoiceId, paymentId, '40.00');

    /* Act: the payment form lists open invoices only */
    const response = await page.request.get('/payments/form');

    /* Assert */
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain('REOPEN-LIST');
  });

  test('it keeps the invoice paid when the edited payment still covers the total', async ({ page }) => {
    /* Arrange: positive control, same request shape but the amount stays 100.00 */
    const [invoiceId, paymentId] = paidInvoice('100.00');

    /* Act: only the note changes */
    const response = await editPayment(page, invoiceId, paymentId, '100.00', 'corrected note');

    /* Assert */
    expect([301, 302, 303]).toContain(response.status());
    expect(dbQuery(`SELECT payment_note FROM ip_payments WHERE payment_id = ${paymentId}`)).toEqual([{ payment_note: 'corrected note' }]);
    expect(statusOf(invoiceId)).toBe(PAID);
    expect(dbQuery(`SELECT invoice_paid, invoice_balance FROM ip_invoice_amounts WHERE invoice_id = ${invoiceId}`)).toEqual([{ invoice_paid: '100.00', invoice_balance: '0.00' }]);
  });

  test('it does not touch the status of an invoice that was not paid', async ({ page }) => {
    /* Arrange: a Viewed invoice with a partial payment; Viewed must not be reset to Sent */
    const invoiceId = invoiceWithItem('100.00', VIEWED);
    const paymentId = seedPayment(invoiceId, '30.00');

    /* Act */
    const response = await editPayment(page, invoiceId, paymentId, '20.00');

    /* Assert */
    expect([301, 302, 303]).toContain(response.status());
    expect(dbQuery(`SELECT invoice_paid, invoice_balance FROM ip_invoice_amounts WHERE invoice_id = ${invoiceId}`)).toEqual([{ invoice_paid: '20.00', invoice_balance: '80.00' }]);
    expect(statusOf(invoiceId)).toBe(VIEWED);
  });

  test('it only reopens the edited invoice', async ({ page }) => {
    /* Arrange: two Paid invoices, only one payment is lowered */
    const [invoiceId, paymentId] = paidInvoice('100.00');
    const [otherId] = paidInvoice('100.00');

    /* Act */
    await editPayment(page, invoiceId, paymentId, '40.00');

    /* Assert */
    expect(statusOf(invoiceId)).toBe(SENT);
    expect(statusOf(otherId)).toBe(PAID);
  });
});
