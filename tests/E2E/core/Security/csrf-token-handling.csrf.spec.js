/**
 * Browser coverage for the CSRF handling in resources/assets/core/js/scripts.js
 * (get_csrf_token_value(), the jQuery ajaxPrefilter and the ajaxComplete token
 * refresh), driven against a real page.
 *
 * The AJAX endpoint is stubbed with page.route() so each test observes exactly
 * what the browser sent and controls exactly what comes back; whether the
 * server accepts a token is covered by the PHPUnit CsrfEnabledAjaxAndDeleteTest.
 */

import { test, expect } from '../../test.js';

const TOKEN_FIELD = '_ip_csrf';
const FILTER_URL = '/filter/ajax/filter_clients';

const metaValue = (page) => page.locator('meta[name="csrf_token_value"]').getAttribute('content');

/** Stub the filter endpoint so a POST to it succeeds without touching the server. */
async function stubFilterEndpoint(page, headers = {}) {
  await page.route(`**${FILTER_URL}`, (route) => route.fulfill({ status: 200, contentType: 'text/plain', headers, body: 'ok' }));
}

/** Fire a jQuery POST from the page and return the request the browser actually sent. */
async function postFromPage(page, data) {
  const sent = page.waitForRequest((request) => request.method() === 'POST' && request.url().includes(FILTER_URL));
  await page.evaluate((payload) => new Promise((resolve) => window.$.post(payload.url, payload.data).always(resolve)), { url: FILTER_URL, data });

  return sent;
}

const occurrences = (haystack, needle) => haystack.split(needle).length - 1;

test.describe('CSRF token handling — client side', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/clients');
  });

  test('it renders the token name and a usable token value in the page head', async ({ page }) => {
    /* Act */
    const name = await page.locator('meta[name="csrf_token_name"]').getAttribute('content');
    const value = await metaValue(page);

    /* Assert */
    expect(name).toBe(TOKEN_FIELD);
    expect(value).toMatch(/^[0-9a-f]{32}$/);
  });

  test('it gives a form the same token as the page head', async ({ page }) => {
    /* Act */
    await page.goto('/clients/form');

    /* Assert */
    await expect(page.locator(`form input[name="${TOKEN_FIELD}"]`).first()).toHaveValue(await metaValue(page));
  });

  test('it reads the token from the meta tag', async ({ page }) => {
    /* Act */
    const fromHelper = await page.evaluate(() => window.get_csrf_token_value());

    /* Assert */
    expect(fromHelper).toBe(await metaValue(page));
  });

  test('it falls back to the csrf cookie when the meta tag is missing', async ({ page, context }) => {
    /* Arrange: the server's own cookie is HttpOnly, so replace it with one the script can read */
    await context.clearCookies({ name: 'ip_csrf_cookie' });

    /* Act */
    const fromHelper = await page.evaluate(() => {
      document.querySelector('meta[name="csrf_token_value"]').remove();
      document.cookie = 'ip_csrf_cookie=cookie%20token; path=/';

      return window.get_csrf_token_value();
    });

    /* Assert */
    expect(fromHelper).toBe('cookie token');
  });

  test('it returns an empty string, never "false" or "undefined", when there is no token anywhere', async ({ page, context }) => {
    /* Arrange */
    await context.clearCookies({ name: 'ip_csrf_cookie' });

    /* Act */
    const fromHelper = await page.evaluate(() => {
      document.querySelector('meta[name="csrf_token_value"]').remove();
      document.cookie = 'ip_csrf_cookie=; path=/; max-age=0';

      return window.get_csrf_token_value();
    });

    /* Assert */
    expect(fromHelper).toBe('');
  });

  test('it sends the token as a body field and as a request header on a jQuery post', async ({ page }) => {
    /* Arrange */
    await stubFilterEndpoint(page);
    const token = await metaValue(page);

    /* Act */
    const request = await postFromPage(page, { filter_query: 'abc' });

    /* Assert */
    expect(request.postData()).toBe(`filter_query=abc&${TOKEN_FIELD}=${token}`);
    expect(request.headers()[`x-${TOKEN_FIELD}`]).toBe(token);
  });

  test('it does not add the token a second time when the body already carries it', async ({ page }) => {
    /* Arrange */
    await stubFilterEndpoint(page);
    const token = await metaValue(page);

    /* Act */
    const request = await postFromPage(page, `${TOKEN_FIELD}=${token}&filter_query=abc`);

    /* Assert */
    expect(occurrences(request.postData(), `${TOKEN_FIELD}=`)).toBe(1);
  });

  test('it leaves a jQuery get request without a token', async ({ page }) => {
    /* Arrange */
    await stubFilterEndpoint(page);
    const sent = page.waitForRequest((request) => request.method() === 'GET' && request.url().includes(FILTER_URL));

    /* Act */
    await page.evaluate((url) => new Promise((resolve) => window.$.get(url, { filter_query: 'abc' }).always(resolve)), FILTER_URL);
    const request = await sent;

    /* Assert */
    expect(request.url()).not.toContain(TOKEN_FIELD);
    expect(request.headers()[`x-${TOKEN_FIELD}`]).toBeUndefined();
  });

  test('it adopts the rotated token the server returns in the response header', async ({ page }) => {
    /* Arrange */
    await stubFilterEndpoint(page, { [`X-${TOKEN_FIELD}`]: 'rotated-token-0123456789abcdef0123' });

    /* Act */
    await postFromPage(page, { filter_query: 'abc' });

    /* Assert */
    expect(await metaValue(page)).toBe('rotated-token-0123456789abcdef0123');
    expect(await page.evaluate(() => window.get_csrf_token_value())).toBe('rotated-token-0123456789abcdef0123');
  });

  test('it sends the rotated token on the next post', async ({ page }) => {
    /* Arrange */
    await stubFilterEndpoint(page, { [`X-${TOKEN_FIELD}`]: 'rotated-token-0123456789abcdef0123' });
    await postFromPage(page, { filter_query: 'first' });

    /* Act */
    const second = await postFromPage(page, { filter_query: 'second' });

    /* Assert */
    expect(second.postData()).toBe(`filter_query=second&${TOKEN_FIELD}=rotated-token-0123456789abcdef0123`);
  });
});
