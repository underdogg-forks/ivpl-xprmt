/**
 * Test-data builders.
 *
 * The E2E database is seeded with only the baseline (one admin, settings, a
 * default invoice group) — every spec creates the rows it needs. There is no
 * per-test DB rollback here (unlike the PHPUnit suite), so every fixture name
 * is made unique with `uniq()` and specs assert on their own unique strings
 * rather than on absolute counts.
 */

import { expect } from '../test.js';
import { idFromUrl } from './app.js';

let counter = 0;

/** A short value that is unique within a run (and readable in the UI / a failure screenshot). */
export function uniq(prefix = 'E2E') {
  counter += 1;

  return `${prefix}-${Date.now().toString(36)}-${counter}`;
}

/**
 * Create a client through the real form and return `{ id, name, surname }`.
 * `client_name` is the only required field; pass overrides for anything else.
 */
export async function createClient(page, overrides = {}) {
  const name = overrides.client_name ?? uniq('Client');
  const surname = overrides.client_surname ?? '';

  await page.goto('/clients/form');
  await page.fill('#client_name', name);
  if (surname) await page.fill('#client_surname', surname);

  for (const [key, value] of Object.entries(overrides)) {
    if (key === 'client_name' || key === 'client_surname') continue;
    const field = page.locator(`[name="${key}"]`).first();
    const type = await field.evaluate((el) => el.getAttribute('type'));
    if (type === 'checkbox' || type === 'radio') {
      await field.setChecked(value === true || value === 1 || value === '1');
    } else {
      await field.fill(String(value));
    }
  }

  await Promise.all([page.waitForURL(/\/clients\/view\/\d+/), page.click('#btn-submit')]);

  const id = idFromUrl(page.url());
  expect(id, 'a created client should redirect to /clients/view/{id}').not.toBeNull();

  return { id, name, surname };
}

/**
 * Create a non-admin (user_type 2) user via a direct authenticated POST to the
 * user form and return `{ id, email, name }`. Driving the whole multi-field
 * user form by hand in every spec that just needs "some other user" is noise;
 * the user form itself is covered by UsersController.spec.js.
 */
export async function createSecondaryUser(page, overrides = {}) {
  const email = overrides.user_email ?? `${uniq('user').toLowerCase()}@test.local`;
  const name = overrides.user_name ?? uniq('User');
  const password = 'password-123';

  const response = await page.request.post('/users/form', {
    form: {
      user_type: '2',
      user_name: name,
      user_email: email,
      user_password: password,
      user_passwordv: password,
      user_language: 'system',
      btn_submit: '1',
      ...overrides,
    },
    maxRedirects: 0,
  });
  expect(response.status(), 'creating the secondary user should redirect').toBe(303);

  // The save redirects to the list, not the edit form, so recover the id from
  // the row the new email now appears in.
  const listHtml = await (await page.request.get('/users')).text();
  const row = listHtml.split(/<tr[ >]/).find((chunk) => chunk.includes(email));
  const id = Number(row?.match(/users\/(?:form|view)\/(\d+)/)?.[1]);
  expect(id, `the new user (${email}) should appear in the user list`).toBeGreaterThan(0);

  return { id, email, name, password };
}
