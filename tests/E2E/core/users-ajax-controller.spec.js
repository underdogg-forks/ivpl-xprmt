/**
 * Browser coverage for application/modules/users/controllers/Ajax.php, mirroring tests/Feature/Core/UsersAjaxControllerTest.php:
 * who may assign clients to which user (save_user_client) and the name search behind the user picker (name_query):
 * active users of one type only, LIKE wildcards taken literally, an optional "anywhere in the name" mode, the company
 * and invoicing-contact columns, and an injection-shaped query that is only ever a literal prefix.
 */

import { test, expect } from '../test.js';
import { loginAs } from '../support/auth.js';
import { seedUser } from '../support/fixtures.js';
import { count, seedAdminUser, seedClientRow } from '../support/quotes.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };
const nameQuery = async (api, query, extra = {}) => {
  const response = await api.get('/users/ajax/name_query/1', { headers: XHR, params: { query, ...extra } });

  return { response, body: await response.json() };
};

test.describe('Users AJAX — save_user_client authorization', () => {
  test('it prevents a non-primary admin from assigning clients to another user', async ({ browser }) => {
    /* Arrange */
    const attacker = seedAdminUser();
    const victimId = seedAdminUser().userId;
    const clientId = seedClientRow({ client_name: 'Test Client' });
    const { context } = await loginAs(browser, attacker.email, 'password');

    /* Act */
    const response = await context.request.post('/users/ajax/save_user_client', { headers: XHR, form: { user_id: String(victimId), client_id: String(clientId) } });

    /* Assert */
    expect(response.status(), 'only the primary admin may assign clients to other users').toBe(403);
    expect(count('ip_user_clients', `user_id = ${victimId} AND client_id = ${clientId}`)).toBe(0);
    await context.close();
  });

  test('it allows a user to assign clients to themselves', async ({ browser }) => {
    /* Arrange */
    const self = seedAdminUser();
    const clientId = seedClientRow({ client_name: 'My Client' });
    const { context } = await loginAs(browser, self.email, 'password');

    /* Act */
    const response = await context.request.post('/users/ajax/save_user_client', { headers: XHR, form: { user_id: String(self.userId), client_id: String(clientId) } });

    /* Assert */
    expect(response.ok(), 'a user should be able to assign clients to their own account').toBe(true);
    expect(count('ip_user_clients', `user_id = ${self.userId} AND client_id = ${clientId}`)).toBe(1);
    await context.close();
  });

  test('it allows the primary admin to assign clients to any user', async ({ page }) => {
    /* Arrange */
    const user = seedUser({ user_type: 2, user_name: 'Non-Admin User' });
    const clientId = seedClientRow({ client_name: 'Assigned By Admin' });

    /* Act */
    const response = await page.request.post('/users/ajax/save_user_client', { headers: XHR, form: { user_id: String(user.id), client_id: String(clientId) } });

    /* Assert */
    expect(response.ok(), 'the primary admin should be able to assign clients to any user').toBe(true);
    expect(count('ip_user_clients', `user_id = ${user.id} AND client_id = ${clientId}`)).toBe(1);
  });
});

test.describe('Users AJAX — name_query', () => {
  test('it returns an empty json list for an empty name query', async ({ page }) => {
    /* Arrange */
    seedUser({ user_name: 'Searchable Person' });

    /* Act */
    const { body } = await nameQuery(page.request, '');

    /* Assert: exactly one JSON document, the search below the guard must not also run and append results */
    expect(body).toEqual([]);
  });

  test('it finds only active users of the requested type by name prefix', async ({ page }) => {
    /* Arrange */
    const match = seedUser({ user_type: 1, user_name: 'Zed Alpha', user_active: 1 });
    seedUser({ user_type: 1, user_name: 'Zed Inactive', user_active: 0 });
    seedUser({ user_type: 2, user_name: 'Zed Guest', user_active: 1 });
    seedUser({ user_type: 1, user_name: 'Unrelated Name', user_active: 1 });

    /* Act */
    const { body } = await nameQuery(page.request, 'Zed');

    /* Assert */
    expect(body).toHaveLength(1);
    expect(String(body[0].id)).toBe(String(match.id));
    expect(body[0].text).toContain('Zed Alpha');
  });

  test('it treats percent and underscore in a name query as literal characters, not wildcards', async ({ page }) => {
    /* Arrange: GHSA-g7f2-mj3r-xx88 follow-up, LIKE wildcards are stripped from the input before it is bound */
    seedUser({ user_type: 1, user_name: 'Zed Alpha', user_active: 1 });

    /* Act */
    const underscore = await nameQuery(page.request, 'Z_d');
    const percent = await nameQuery(page.request, 'Z%d');
    const plain = await nameQuery(page.request, 'Ze%');

    /* Assert: 'Z_d' and 'Z%d' would match 'Zed' if the wildcards were live */
    expect(underscore.body, '"_" must not act as a single-character wildcard').toEqual([]);
    expect(percent.body, '"%" must not act as a multi-character wildcard').toEqual([]);
    expect(plain.body, 'the stripped term "Ze" is still a prefix of "Zed Alpha"').toHaveLength(1);
  });

  test('it only matches the middle of a name when permissive search is requested', async ({ page }) => {
    /* Arrange */
    seedUser({ user_type: 1, user_name: 'Zed Alpha', user_active: 1 });

    /* Act */
    const strict = await nameQuery(page.request, 'Alpha');
    const permissive = await nameQuery(page.request, 'Alpha', { permissive_search_users: '1' });

    /* Assert */
    expect(strict.body).toEqual([]);
    expect(permissive.body).toHaveLength(1);
  });

  test('it also searches the company and invoicing contact columns', async ({ page }) => {
    /* Arrange */
    const byCompany = seedUser({ user_type: 1, user_name: 'Nomatch One', user_company: 'Acme Holdings' });
    const byContact = seedUser({ user_type: 1, user_name: 'Nomatch Two', user_invoicing_contact: 'Quincy Contact' });

    /* Act */
    const company = await nameQuery(page.request, 'Acme');
    const contact = await nameQuery(page.request, 'Quincy');

    /* Assert */
    expect(company.body.map((row) => String(row.id))).toEqual([String(byCompany.id)]);
    expect(contact.body.map((row) => String(row.id))).toEqual([String(byContact.id)]);
  });

  test('it returns an empty list for a sql injection shaped name query', async ({ page }) => {
    /* Arrange */
    seedUser({ user_type: 1, user_name: 'Zed Alpha', user_active: 1 });

    /* Act */
    const { response, body } = await nameQuery(page.request, "x' OR '1'='1");

    /* Assert: bound as a literal prefix, so nothing matches and nothing errors */
    expect(response.status()).toBe(200);
    expect(body).toEqual([]);
  });
});
