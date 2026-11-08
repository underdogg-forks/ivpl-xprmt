/**
 * Arrange-only builders for the supplier invoice specs: the same rows the PHPUnit
 * SupplierInvoicesControllerTest seeds with databaseInsert(), written straight to the database.
 */

import { dbInsert, dbQuery } from './db.js';

export function seedSupplier(name) {
  return dbInsert('ip_suppliers', {
    supplier_name: name,
    supplier_active: 1,
    supplier_date_created: '2026-10-04',
    supplier_date_modified: '2026-10-04',
  });
}

export function seedSupplierInvoice(supplierId, number, total, overrides = {}) {
  return dbInsert('ip_supplier_invoices', {
    supplier_id: supplierId,
    incoming_response_id: null,
    supplier_invoice_number: number,
    supplier_invoice_date: '2026-10-04',
    currency_code: 'EUR',
    subtotal: total,
    tax_total: '0.00',
    total,
    amount_paid: '0.00',
    status: 'received',
    created_at: '2026-10-04 12:00:00',
    updated_at: '2026-10-04 12:00:00',
    ...overrides,
  });
}

export const invoiceRow = (id) => dbQuery(`SELECT * FROM ip_supplier_invoices WHERE supplier_invoice_id = ${Number(id)}`)[0] ?? null;

export const countRows = (table, where = '1=1') => Number(dbQuery(`SELECT COUNT(*) AS n FROM ${table} WHERE ${where}`)[0].n);
