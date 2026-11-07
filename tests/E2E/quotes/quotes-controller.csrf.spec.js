/**
 * The CSRF-enabled half of tests/Feature/Quotes/QuotesControllerTest.php: the delete regression (#1694) and the
 * whole guest portal (guest/controllers/Quotes.php), which the PHPUnit tests run with CSRF protection on. The
 * portal user is logged in on the CSRF-enabled server and clicks the buttons the pages render.
 */

import { test, expect } from '../test.js';
import { E2E_CSRF_BASE_URL } from '../config.js';
import { dbQuery } from '../support/db.js';
import { count, guestSession, QUOTE_STATUS, quoteRow, seedClientRow, seedGuestQuote, seedQuote } from '../support/quotes.js';

const { DRAFT, SENT, VIEWED, APPROVED, REJECTED } = QUOTE_STATUS;
const status = (id) => Number(quoteRow(id).quote_status_id);

test.describe('Quotes — delete with CSRF protection on', () => {
  test('it still deletes a quote when csrf protection is on and the token is valid', async ({ page }) => {
    /* Arrange */
    const id = seedQuote(seedClientRow());
    await page.goto('/quotes/status/all');

    /* Act */
    const row = page.locator('tr', { has: page.locator(`form[action*="quotes/delete/${id}"]`) });
    await row.locator('.dropdown-toggle').click();
    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([page.waitForLoadState('load'), row.locator('button.dropdown-button').click()]);

    /* Assert */
    expect(count('ip_quotes', `quote_id = ${id}`)).toBe(0);
  });

  test('it does not delete a quote when the csrf token is missing', async ({ page }) => {
    /* Arrange */
    const id = seedQuote(seedClientRow(), { quote_number: 'QUO-CSRF-KEPT' });

    /* Act */
    const response = await page.request.post(`/quotes/delete/${id}`, { maxRedirects: 0 });

    /* Assert: it never reaches the controller */
    expect(response.status()).toBe(403);
    expect(count('ip_quotes', `quote_id = ${id} AND quote_number = 'QUO-CSRF-KEPT'`)).toBe(1);
  });
});

test.describe('Quotes — guest portal', () => {
  /** Two clients, a portal user for the first, and a ready browser session for them. */
  async function portal(browser) {
    const ownClient = seedClientRow({ client_name: 'Own Client' });
    const otherClient = seedClientRow({ client_name: 'Other Client' });
    const { context, page } = await guestSession(browser, ownClient, E2E_CSRF_BASE_URL);

    return { ownClient, otherClient, context, page };
  }

  test('it redirects the guest index to open quotes', async ({ browser }) => {
    /* Arrange */
    const { context, page } = await portal(browser);

    /* Act */
    await page.goto('/guest/quotes');

    /* Assert */
    await expect(page).toHaveURL(/\/guest\/quotes\/status\/open$/);
    await context.close();
  });

  test("it lists only the guest's own open quotes", async ({ browser }) => {
    /* Arrange */
    const { ownClient, otherClient, context, page } = await portal(browser);
    seedGuestQuote(ownClient, SENT, 'OWN-SENT');
    seedGuestQuote(ownClient, VIEWED, 'OWN-VIEWED');
    seedGuestQuote(ownClient, DRAFT, 'OWN-DRAFT');
    seedGuestQuote(ownClient, APPROVED, 'OWN-APPROVED');
    seedGuestQuote(otherClient, SENT, 'OTHER-SENT');

    /* Act */
    await page.goto('/guest/quotes/status/open');

    /* Assert */
    await expect(page.locator('body')).toContainText('OWN-SENT');
    await expect(page.locator('body')).toContainText('OWN-VIEWED');
    for (const hidden of ['OWN-DRAFT', 'OWN-APPROVED', 'OTHER-SENT']) {
      await expect(page.locator('body')).not.toContainText(hidden);
    }
    await context.close();
  });

  const STATUS_FILTERS = {
    'all shows every guest-visible own quote': ['all', ['OWN-SENT', 'OWN-APPROVED', 'OWN-REJECTED'], ['OWN-DRAFT', 'OTHER-APPROVED']],
    viewed: ['viewed', ['OWN-VIEWED'], ['OWN-SENT', 'OWN-APPROVED']],
    approved: ['approved', ['OWN-APPROVED'], ['OWN-REJECTED', 'OTHER-APPROVED']],
    rejected: ['rejected', ['OWN-REJECTED'], ['OWN-APPROVED', 'OTHER-APPROVED']],
  };

  for (const [label, [filter, shown, hidden]] of Object.entries(STATUS_FILTERS)) {
    test(`it filters guest listing by status without crossing clients (${label})`, async ({ browser }) => {
      /* Arrange */
      const { ownClient, otherClient, context, page } = await portal(browser);
      seedGuestQuote(ownClient, DRAFT, 'OWN-DRAFT');
      seedGuestQuote(ownClient, SENT, 'OWN-SENT');
      seedGuestQuote(ownClient, VIEWED, 'OWN-VIEWED');
      seedGuestQuote(ownClient, APPROVED, 'OWN-APPROVED');
      seedGuestQuote(ownClient, REJECTED, 'OWN-REJECTED');
      seedGuestQuote(otherClient, APPROVED, 'OTHER-APPROVED');

      /* Act */
      await page.goto(`/guest/quotes/status/${filter}`);

      /* Assert */
      for (const number of shown) await expect(page.locator('body')).toContainText(number);
      for (const number of hidden) await expect(page.locator('body')).not.toContainText(number);
      await context.close();
    });
  }

  test('it shows an own quote and marks a sent quote as viewed', async ({ browser }) => {
    /* Arrange */
    const { ownClient, context, page } = await portal(browser);
    const quoteId = seedGuestQuote(ownClient, SENT, 'OWN-VIEW-1');

    /* Act */
    await page.goto(`/guest/quotes/view/${quoteId}`);

    /* Assert */
    await expect(page.locator('body')).toContainText('OWN-VIEW-1');
    expect(status(quoteId)).toBe(VIEWED);
    await context.close();
  });

  test("it hides another client's quote and does not mark it viewed", async ({ browser }) => {
    /* Arrange */
    const { otherClient, context, page } = await portal(browser);
    const quoteId = seedGuestQuote(otherClient, SENT, 'OTHER-VIEW-1');

    /* Act */
    const response = await page.goto(`/guest/quotes/view/${quoteId}`);

    /* Assert */
    expect(response.status()).toBe(404);
    await expect(page.locator('body')).not.toContainText('OTHER-VIEW-1');
    expect(status(quoteId)).toBe(SENT);
    await context.close();
  });

  test('it hides a draft quote even from its own client', async ({ browser }) => {
    /* Arrange */
    const { ownClient, context, page } = await portal(browser);
    const quoteId = seedGuestQuote(ownClient, DRAFT, 'OWN-DRAFT-VIEW');

    /* Act */
    const response = await page.goto(`/guest/quotes/view/${quoteId}`);

    /* Assert */
    expect(response.status()).toBe(404);
    await expect(page.locator('body')).not.toContainText('OWN-DRAFT-VIEW');
    await context.close();
  });

  test("it refuses the pdf of another client's quote", async ({ browser }) => {
    /* Arrange */
    const { otherClient, context } = await portal(browser);
    const quoteId = seedGuestQuote(otherClient, SENT, 'OTHER-PDF-1');

    /* Act */
    const response = await context.request.get(`/guest/quotes/generate_pdf/${quoteId}`, { maxRedirects: 0 });

    /* Assert */
    expect(response.status()).toBe(404);
    expect(status(quoteId)).toBe(SENT);
    await context.close();
  });

  const DECISIONS = { approve: APPROVED, reject: REJECTED };

  for (const [action, expectedStatus] of Object.entries(DECISIONS)) {
    test(`it applies the ${action} decision to an own open quote`, async ({ browser }) => {
      /* Arrange */
      const { ownClient, context, page } = await portal(browser);
      const quoteId = seedGuestQuote(ownClient, SENT, 'OWN-DECIDE-1');
      await page.goto(`/guest/quotes/view/${quoteId}`);

      /* Act */
      await page.locator(`form[action*="guest/quotes/${action}/${quoteId}"] button[type="submit"]`).click();

      /* Assert */
      expect(status(quoteId)).toBe(expectedStatus);
      await context.close();
    });

    test(`it cannot ${action} another client's quote`, async ({ browser }) => {
      /* Arrange: a valid token from the portal's own page, an id that is not theirs */
      const { ownClient, otherClient, context, page } = await portal(browser);
      const own = seedGuestQuote(ownClient, SENT, 'OWN-FOR-TOKEN');
      const quoteId = seedGuestQuote(otherClient, SENT, 'OTHER-DECIDE-1');
      await page.goto(`/guest/quotes/view/${own}`);
      const token = await page.locator('meta[name="csrf_token_value"]').getAttribute('content');

      /* Act */
      const response = await context.request.post(`/guest/quotes/${action}/${quoteId}`, { form: { _ip_csrf: token }, maxRedirects: 0 });

      /* Assert */
      expect(response.status()).toBe(404);
      expect(status(quoteId)).toBe(SENT);
      await context.close();
    });

    for (const [label, startStatus] of [['a decision that was already made', APPROVED], ['a draft quote', DRAFT]]) {
      test(`it cannot ${action} ${label}`, async ({ browser }) => {
        /* Arrange */
        const { ownClient, context, page } = await portal(browser);
        const open = seedGuestQuote(ownClient, SENT, 'OWN-FOR-TOKEN');
        const quoteId = seedGuestQuote(ownClient, startStatus, 'OWN-FINAL-1');
        await page.goto(`/guest/quotes/view/${open}`);
        const token = await page.locator('meta[name="csrf_token_value"]').getAttribute('content');

        /* Act */
        const response = await context.request.post(`/guest/quotes/${action}/${quoteId}`, { form: { _ip_csrf: token }, maxRedirects: 0 });

        /* Assert */
        expect(response.status()).toBe(404);
        expect(status(quoteId)).toBe(startStatus);
        await context.close();
      });
    }

    test(`it ignores a ${action} decision sent as a plain get`, async ({ browser }) => {
      /* Arrange */
      const { ownClient, context } = await portal(browser);
      const quoteId = seedGuestQuote(ownClient, SENT, 'OWN-GET-1');

      /* Act */
      const response = await context.request.get(`/guest/quotes/${action}/${quoteId}`, { maxRedirects: 0 });

      /* Assert */
      expect(response.status()).toBe(404);
      expect(status(quoteId)).toBe(SENT);
      await context.close();
    });
  }
});
