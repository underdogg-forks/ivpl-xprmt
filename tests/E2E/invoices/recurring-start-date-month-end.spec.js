/**
 * Browser coverage for the "Create Recurring" start date pre-fill. Mirrors
 * tests/Feature/Invoices/RecurringStartDateMonthEndTest.php: invoices/ajax/get_recur_start_date must land inside the
 * next period when the invoice date is the last days of a month.
 */

import { test, expect } from '../test.js';
import { dbExec } from '../support/db.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

const startDates = [
  ['oct 31 monthly', '2026-10-31', '1M', '2026-11-30'],
  ['jan 31 monthly', '2027-01-31', '1M', '2027-02-28'],
  ['jan 30 monthly', '2027-01-30', '1M', '2027-02-28'],
  ['mar 31 quarterly', '2027-03-31', '3M', '2027-06-30'],
  ['feb 29 yearly', '2028-02-29', '1Y', '2029-02-28'],
  ['mid month is unchanged', '2026-10-15', '1M', '2026-11-15'],
  ['weekly is unchanged', '2026-10-31', '1W', '2026-11-07'],
];

test.describe('Invoices AJAX — recurring start date at month end', () => {
  for (const [label, invoiceDate, frequency, expected] of startDates) {
    test(`it prefills a start date inside the next period (${label})`, async ({ page }) => {
      /* Arrange: the endpoint reads and writes dates in the configured format */
      dbExec("UPDATE ip_settings SET setting_value = 'Y-m-d' WHERE setting_key = 'date_format'");

      /* Act */
      const response = await page.request.post('/invoices/ajax/get_recur_start_date', {
        headers: XHR,
        form: { invoice_date: invoiceDate, recur_frequency: frequency },
      });

      /* Assert */
      expect(response.status()).toBe(200);
      expect((await response.text()).trim()).toBe(expected);
    });
  }
});
