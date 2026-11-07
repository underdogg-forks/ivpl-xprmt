/**
 * Helpers for the chromium-csrf project: a "valid token" is the one a rendered page carries in its head, sent with
 * the cookie the server set for it (the page's request context shares the cookie jar).
 */

export const csrfTokenFrom = async (page, path = '/dashboard') => {
  await page.goto(path);

  return page.locator('meta[name="csrf_token_value"]').getAttribute('content');
};

export const postWithToken = async (page, url, form = {}) => page.request.post(url, { form: { ...form, _ip_csrf: await csrfTokenFrom(page) }, maxRedirects: 0 });

export const postWithoutToken = (page, url, form = {}) => page.request.post(url, { form, maxRedirects: 0 });

export const isRedirect = (response) => response.status() >= 300 && response.status() < 400;
