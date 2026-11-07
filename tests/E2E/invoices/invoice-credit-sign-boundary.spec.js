/**
 * Browser coverage for tests/Feature/Invoices/InvoiceCreditSignBoundaryTest.php: where a credit invoice's sign
 * rules differ from a regular invoice's, and that the negative-total guard only protects regular invoices.
 */

import { test, expect } from '../test.js';
import { dbExec, dbQuery } from '../support/db.js';
import { creditInvoice, existingItem, invoiceAmounts, invoiceTotal, invoiceWithOneItem, saveItems, seedInvoiceSettings, smallRow, smuggleBadRow } from '../support/invoices.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

async function creditInvoiceWithItem(page) {
  const { creditId } = await creditInvoice(page);
  const [item] = dbQuery(`SELECT item_id FROM ip_invoice_items WHERE invoice_id = ${creditId}`);

  return { creditId, itemId: Number(item.item_id) };
}

test.describe('Invoice credit sign boundaries', () => {
  test.beforeEach(() => seedInvoiceSettings());

  test('it rejects a negative item discount and a negative global discount on a credit invoice', async ({ page }) => {
    /* Arrange */
    const { creditId, itemId } = await creditInvoiceWithItem(page);
    const item = { ...existingItem(creditId, itemId, '-2', '150'), item_discount_amount: '-5' };

    /* Act */
    const json = await saveItems(page, creditId, [item], { invoice_discount_amount: '-10', invoice_discount_percent: '-3' });

    /* Assert */
    expect(json.success).toBe(0);
    expect(Object.keys(json.validation_errors).sort()).toEqual(['invoice_discount_amount', 'invoice_discount_percent', 'item_discount_amount']);
    expect(invoiceTotal(creditId)).toBe(-300);
  });

  test('it accepts a zero quantity line on a credit invoice', async ({ page }) => {
    /* Arrange */
    const { creditId, itemId } = await creditInvoiceWithItem(page);

    /* Act */
    const json = await saveItems(page, creditId, [existingItem(creditId, itemId, '0', '150')]);

    /* Assert */
    expect(json.success, JSON.stringify(json)).toBe(1);
    expect(invoiceTotal(creditId)).toBe(0);
  });

  test('it names the credit message for a positive quantity on a credit invoice and the plain one on a regular invoice', async ({ page }) => {
    /* Arrange */
    const credit = await creditInvoiceWithItem(page);
    const regular = await invoiceWithOneItem(page);

    /* Act */
    const onCredit = await saveItems(page, credit.creditId, [existingItem(credit.creditId, credit.itemId, '1', '150')]);
    const onRegular = await saveItems(page, regular.invoiceId, [existingItem(regular.invoiceId, regular.itemId, '-1', '150')]);

    /* Assert */
    expect(onCredit.validation_errors.item_quantity).toBe('Quantities on a credit invoice must be zero or negative.');
    expect(onRegular.validation_errors.item_quantity).toBe('The item quantity cannot be negative.');
  });

  test('it keeps the stored amounts of a regular invoice intact when the negative total guard trips', async ({ page }) => {
    /* Arrange */
    const { invoiceId } = await invoiceWithOneItem(page);
    smuggleBadRow(invoiceId);
    const small = smallRow(invoiceId);
    const before = invoiceAmounts(invoiceId);

    /* Act */
    await page.request.post(`/invoices/ajax/delete_item/${invoiceId}`, { headers: XHR, form: { item_id: String(small) } });

    /* Assert */
    const after = invoiceAmounts(invoiceId);
    expect(after.invoice_total).toBe(before.invoice_total);
    expect(after.invoice_balance).toBe(before.invoice_balance);
    expect(after.invoice_item_subtotal).toBe(before.invoice_item_subtotal);
  });

  test('it lets the same recalculation store a negative total for a credit invoice', async ({ page }) => {
    /* Arrange: identical rows, but the invoice is a credit invoice (positive control for the guard) */
    const { invoiceId } = await invoiceWithOneItem(page);
    smuggleBadRow(invoiceId);
    const small = smallRow(invoiceId);
    dbExec(`UPDATE ip_invoice_amounts SET invoice_sign = '-1' WHERE invoice_id = ${invoiceId}`);

    /* Act */
    await page.request.post(`/invoices/ajax/delete_item/${invoiceId}`, { headers: XHR, form: { item_id: String(small) } });

    /* Assert: 300 - 500 */
    expect(invoiceTotal(invoiceId)).toBe(-200);
  });

  test('it lets the recalculation store a negative total when the invoice credits a parent', async ({ page }) => {
    /* Arrange */
    const { invoiceId } = await invoiceWithOneItem(page);
    smuggleBadRow(invoiceId);
    const small = smallRow(invoiceId);
    dbExec(`UPDATE ip_invoices SET creditinvoice_parent_id = 1 WHERE invoice_id = ${invoiceId}`);

    /* Act */
    await page.request.post(`/invoices/ajax/delete_item/${invoiceId}`, { headers: XHR, form: { item_id: String(small) } });

    /* Assert */
    expect(invoiceTotal(invoiceId)).toBe(-200);
  });
});
