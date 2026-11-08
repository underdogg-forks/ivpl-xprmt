/**
 * Browser-side mirror of tests/Feature/Core/Security/GeneratePdfCsrfGateTest.php. GHSA-6pr3-c7rf-gc7x (invoices) and
 * GHSA-257j-c733-6h5f (quotes): generate_pdf must only mark a document as sent and assign its number when the GET carries
 * a token matching the CSRF cookie. This pins the number assignment, each rejected token shape, the setting switch and
 * that the PDF itself is still served to a forged request. Runs in the chromium-csrf project.
 */

import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '../../test.js';
import { E2E_CSRF_BASE_URL } from '../../config.js';
import { dbQuery } from '../../support/db.js';
import { seedInvoiceRow } from '../../support/invoices.js';
import { seedClientRow, seedQuote, setSetting } from '../../support/quotes.js';

const TOKEN = 'gate-csrf-token-0123456789-abcdef';
const adminState = () => JSON.parse(fs.readFileSync(path.resolve('tests/E2E/.auth/admin.json'), 'utf8'));

/** An API client logged in as the admin whose cookie jar holds `csrfCookie` (or none), so the cookie value is exactly ours. */
async function apiWithCookie(playwright, csrfCookie) {
  const state = adminState();
  const cookies = state.cookies.filter((cookie) => cookie.name !== 'ip_csrf_cookie');
  if (csrfCookie !== undefined) {
    cookies.push({ name: 'ip_csrf_cookie', value: csrfCookie, domain: 'localhost', path: '/', expires: -1, httpOnly: false, secure: false, sameSite: 'Lax' });
  }

  return playwright.request.newContext({ baseURL: E2E_CSRF_BASE_URL, storageState: { cookies, origins: [] } });
}

const REJECTED_TOKENS = {
  'no token and no cookie': [{}, undefined],
  'token without cookie': [{ _ip_csrf: TOKEN }, undefined],
  'cookie without token': [{}, TOKEN],
  'mismatching token': [{ _ip_csrf: 'forged-token-0123456789-abcdefghi' }, TOKEN],
  'token is a prefix': [{ _ip_csrf: TOKEN.slice(0, -1) }, TOKEN],
  'empty token and cookie': [{ _ip_csrf: '' }, ''],
  'array token': [{ '_ip_csrf[]': TOKEN }, TOKEN],
};

const invoiceRow = (id) => dbQuery(`SELECT invoice_status_id, invoice_number FROM ip_invoices WHERE invoice_id = ${id}`)[0];
const quoteRow = (id) => dbQuery(`SELECT quote_status_id, quote_number FROM ip_quotes WHERE quote_id = ${id}`)[0];
const draftInvoice = () => seedInvoiceRow(seedClientRow(), { invoice_number: '' });
const draftQuote = () => seedQuote(seedClientRow(), { quote_status_id: 1, quote_number: '' });
const valid = { _ip_csrf: TOKEN };

test.beforeEach(() => {
  setSetting('generate_invoice_number_for_draft', '0');
  setSetting('generate_quote_number_for_draft', '0');
});

test.describe('generate_pdf CSRF gate — invoices', () => {
  for (const [label, [query, cookie]] of Object.entries(REJECTED_TOKENS)) {
    test(`it neither marks nor numbers an invoice for a rejected token (${label})`, async ({ playwright }) => {
      /* Arrange */
      setSetting('mark_invoices_sent_pdf', '1');
      const forged = draftInvoice();
      const genuine = draftInvoice();
      const forgedApi = await apiWithCookie(playwright, cookie);
      const genuineApi = await apiWithCookie(playwright, TOKEN);

      /* Act */
      const forgedResponse = await forgedApi.get(`/invoices/generate_pdf/${forged}/1`, { params: query });
      const genuineResponse = await genuineApi.get(`/invoices/generate_pdf/${genuine}/0`, { params: valid });

      /* Assert */
      expect(forgedResponse.status()).toBe(200);
      expect((await forgedResponse.body()).toString('latin1').startsWith('%PDF')).toBe(true);
      expect(genuineResponse.status()).toBeLessThan(500);
      expect(invoiceRow(forged)).toEqual({ invoice_status_id: 1, invoice_number: '' });
      expect(invoiceRow(genuine).invoice_status_id, 'positive control: a valid pair marks the invoice').toBe(2);
      await forgedApi.dispose();
      await genuineApi.dispose();
    });
  }

  test('it assigns the invoice number together with the sent status for a valid token', async ({ playwright }) => {
    /* Arrange */
    setSetting('mark_invoices_sent_pdf', '1');
    const invoiceId = draftInvoice();
    const api = await apiWithCookie(playwright, TOKEN);

    /* Act */
    await api.get(`/invoices/generate_pdf/${invoiceId}/0`, { params: valid });

    /* Assert */
    expect(invoiceRow(invoiceId).invoice_status_id).toBe(2);
    expect(invoiceRow(invoiceId).invoice_number, 'the official number is assigned with the status flip').not.toBe('');
    await api.dispose();
  });

  test('it still streams the invoice pdf to a request without a token', async ({ playwright }) => {
    /* Arrange */
    setSetting('mark_invoices_sent_pdf', '1');
    const invoiceId = seedInvoiceRow(seedClientRow());
    const api = await apiWithCookie(playwright, undefined);

    /* Act */
    const response = await api.get(`/invoices/generate_pdf/${invoiceId}/1`);

    /* Assert: the safe read is served, only the mutation is withheld */
    expect(response.status()).toBe(200);
    expect((await response.body()).toString('latin1').startsWith('%PDF')).toBe(true);
    expect(invoiceRow(invoiceId).invoice_status_id).toBe(1);
    await api.dispose();
  });
});

test.describe('generate_pdf CSRF gate — quotes', () => {
  for (const [label, [query, cookie]] of Object.entries(REJECTED_TOKENS)) {
    test(`it neither marks nor numbers a quote for a rejected token (${label})`, async ({ playwright }) => {
      /* Arrange */
      setSetting('mark_quotes_sent_pdf', '1');
      const forged = draftQuote();
      const genuine = draftQuote();
      const forgedApi = await apiWithCookie(playwright, cookie);
      const genuineApi = await apiWithCookie(playwright, TOKEN);

      /* Act */
      const forgedResponse = await forgedApi.get(`/quotes/generate_pdf/${forged}/1`, { params: query });
      const genuineResponse = await genuineApi.get(`/quotes/generate_pdf/${genuine}/0`, { params: valid });

      /* Assert */
      expect(forgedResponse.status()).toBe(200);
      expect((await forgedResponse.body()).toString('latin1').startsWith('%PDF')).toBe(true);
      expect(genuineResponse.status()).toBeLessThan(500);
      expect(quoteRow(forged)).toEqual({ quote_status_id: 1, quote_number: '' });
      expect(quoteRow(genuine).quote_status_id, 'positive control: a valid pair marks the quote').toBe(2);
      await forgedApi.dispose();
      await genuineApi.dispose();
    });
  }

  test('it assigns the quote number together with the sent status for a valid token', async ({ playwright }) => {
    /* Arrange */
    setSetting('mark_quotes_sent_pdf', '1');
    const quoteId = draftQuote();
    const api = await apiWithCookie(playwright, TOKEN);

    /* Act */
    await api.get(`/quotes/generate_pdf/${quoteId}/0`, { params: valid });

    /* Assert */
    expect(quoteRow(quoteId).quote_status_id).toBe(2);
    expect(quoteRow(quoteId).quote_number, 'the official number is assigned with the status flip').not.toBe('');
    await api.dispose();
  });
});

test.describe('generate_pdf CSRF gate — the mark-sent setting', () => {
  test('it leaves documents alone when the mark-sent setting is off, even with a valid token', async ({ playwright }) => {
    /* Arrange */
    setSetting('mark_invoices_sent_pdf', '0');
    setSetting('mark_quotes_sent_pdf', '0');
    const invoiceId = draftInvoice();
    const quoteId = draftQuote();
    const api = await apiWithCookie(playwright, TOKEN);

    /* Act */
    await api.get(`/invoices/generate_pdf/${invoiceId}/0`, { params: valid });
    await api.get(`/quotes/generate_pdf/${quoteId}/0`, { params: valid });

    /* Assert */
    expect(invoiceRow(invoiceId)).toEqual({ invoice_status_id: 1, invoice_number: '' });
    expect(quoteRow(quoteId)).toEqual({ quote_status_id: 1, quote_number: '' });
    await api.dispose();
  });
});
