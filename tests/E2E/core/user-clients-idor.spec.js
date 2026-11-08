/**
 * Browser-side mirror of tests/Feature/Core/Users/UserClientsIdorTest.php (GHSA-5rqm-w9p8-gp7w): only the primary
 * administrator may map clients to another user, grant "all clients", or unassign another user's mapping; any user may
 * unassign their own; and a user id that is not a plain integer is refused before it can authorise one user and write
 * to another. A "secondary admin" is a second administrator who logs in for real in a context of their own.
 */

import { test, expect } from '../test.js';
import { loginAs } from '../support/auth.js';
import { dbExec, dbInsert, dbQuery } from '../support/db.js';
import { count, seedAdminUser, seedClientRow } from '../support/quotes.js';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

/** The route a redirect points at, without the host or the optional /index.php prefix. */
const redirectRoute = (response) => new URL(response.headers().location, 'http://localhost').pathname.replace(/^\/index\.php/, '').replace(/^\/|\/$/g, '');
const userFlag = (id) => Number(dbQuery(`SELECT user_all_clients FROM ip_users WHERE user_id = ${id}`)[0].user_all_clients);
const post = (api, url, form = {}) => api.post(url, { form, maxRedirects: 0 });

/** A logged-in secondary administrator; the caller closes the context. */
async function secondaryAdmin(browser) {
  const admin = seedAdminUser();
  const { context } = await loginAs(browser, admin.email, 'password');

  return { userId: admin.userId, api: context.request, context };
}

test.describe('User clients — a secondary admin is refused', () => {
  test('it denies a secondary admin creating a client mapping for another user', async ({ browser }) => {
    /* Arrange */
    const { api, context } = await secondaryAdmin(browser);
    const victimId = seedAdminUser().userId;
    const clientId = seedClientRow();

    /* Act */
    const response = await post(api, `/user_clients/create/${victimId}`, { user_id: victimId, client_id: clientId });

    /* Assert */
    expect(response.status()).toBe(403);
    expect(count('ip_user_clients', `user_id = ${victimId}`)).toBe(0);
    await context.close();
  });

  test('it does not let a fractional user id pass the self check and land on the next user', async ({ browser }) => {
    /* Arrange: "S.5" is authorised as S by an (int) cast, but "2.5" in an INT column rounds to the next user */
    const { userId, api, context } = await secondaryAdmin(browser);
    const victimId = seedAdminUser().userId;
    const clientId = seedClientRow();

    /* Act */
    const response = await api.post('/users/ajax/save_user_client', { headers: XHR, form: { user_id: `${userId}.5`, client_id: String(clientId) }, maxRedirects: 0 });

    /* Assert */
    expect(response.status(), 'a non-integer user id must be refused').toBe(403);
    expect(count('ip_user_clients', `user_id = ${victimId}`)).toBe(0);
    expect(count('ip_user_clients', `user_id = ${userId} AND client_id = ${clientId}`)).toBe(0);
    await context.close();
  });

  test('it denies a secondary admin deleting another users client mapping', async ({ browser }) => {
    /* Arrange */
    const { api, context } = await secondaryAdmin(browser);
    const victimId = seedAdminUser().userId;
    const mappingId = dbInsert('ip_user_clients', { user_id: victimId, client_id: seedClientRow() });

    /* Act */
    const response = await post(api, `/user_clients/delete/${mappingId}`);

    /* Assert */
    expect(response.status()).toBe(403);
    expect(count('ip_user_clients', `user_client_id = ${mappingId}`)).toBe(1);
    await context.close();
  });

  test('it denies a secondary admin granting themselves all clients', async ({ browser }) => {
    /* Arrange */
    const { userId, api, context } = await secondaryAdmin(browser);
    const clientId = seedClientRow();

    /* Act */
    const response = await post(api, `/user_clients/create/${userId}`, { user_id: userId, client_id: clientId, user_all_clients: '1' });

    /* Assert */
    expect(response.status()).toBe(403);
    expect(userFlag(userId)).toBe(0);
    await context.close();
  });

  test('it denies a secondary admin unassigning another users client mapping via delete_user_client', async ({ browser }) => {
    /* Arrange: Users::delete_user_client has no other authorisation test, the parity test acts as the primary admin */
    const { api, context } = await secondaryAdmin(browser);
    const victimId = seedAdminUser().userId;
    const mappingId = dbInsert('ip_user_clients', { user_id: victimId, client_id: seedClientRow() });

    /* Act */
    const response = await post(api, `/users/delete_user_client/${victimId}/${mappingId}`);

    /* Assert */
    expect(response.status()).toBe(403);
    expect(count('ip_user_clients', `user_client_id = ${mappingId}`)).toBe(1);
    await context.close();
  });

  test('it allows a user to unassign their own client mapping via delete_user_client', async ({ browser }) => {
    /* Arrange: the own-account carve-out, allowed even for a non-primary admin */
    const { userId, api, context } = await secondaryAdmin(browser);
    const mappingId = dbInsert('ip_user_clients', { user_id: userId, client_id: seedClientRow() });

    /* Act */
    const response = await post(api, `/users/delete_user_client/${userId}/${mappingId}`);

    /* Assert */
    expect(response.status()).toBe(303);
    expect(redirectRoute(response)).toBe(`users/form/${userId}`);
    expect(count('ip_user_clients', `user_client_id = ${mappingId}`)).toBe(0);
    await context.close();
  });
});

test.describe('User clients — the primary admin may', () => {
  test('it still collects clients in the session while a new user has no id yet', async ({ page }) => {
    /* Arrange: the new-user form has no user_id, so the integer guard must not apply to it */
    const clientId = seedClientRow({ client_name: 'Collected Client' });

    /* Act */
    const response = await page.request.post('/users/ajax/save_user_client', { headers: XHR, form: { user_id: '', client_id: String(clientId) }, maxRedirects: 0 });
    const table = await page.request.get('/users/ajax/load_user_client_table/0', { headers: XHR });

    /* Assert: an empty user id is the new-user flow, not an invalid id */
    expect(response.status()).not.toBe(403);
    expect(await table.text()).toContain('Collected Client');
  });

  test('it allows the primary admin to create a client mapping for another user', async ({ page }) => {
    /* Arrange */
    const targetId = seedAdminUser().userId;
    const clientId = seedClientRow();

    /* Act */
    const response = await post(page.request, `/user_clients/create/${targetId}`, { user_id: targetId, client_id: clientId });

    /* Assert */
    expect(response.status()).toBe(303);
    expect(redirectRoute(response)).toBe(`user_clients/user/${targetId}`);
    expect(count('ip_user_clients', `user_id = ${targetId} AND client_id = ${clientId}`)).toBe(1);
  });

  test('it allows the primary admin to grant all clients', async ({ page }) => {
    /* Arrange: a second client is never named in the request; only set_all_clients_user() would assign it */
    const targetId = seedAdminUser().userId;
    const named = seedClientRow();
    const unnamed = seedClientRow();

    /* Act */
    const response = await post(page.request, `/user_clients/create/${targetId}`, { user_id: targetId, client_id: named, user_all_clients: '1' });

    /* Assert: the flag is set AND every client was assigned */
    expect(redirectRoute(response)).toBe(`user_clients/user/${targetId}`);
    expect(userFlag(targetId)).toBe(1);
    expect(count('ip_user_clients', `user_id = ${targetId} AND client_id = ${unnamed}`)).toBe(1);
  });

  test('it does not grant all clients when only a single client is requested', async ({ page }) => {
    /* Arrange */
    const targetId = seedAdminUser().userId;
    const named = seedClientRow();
    const unnamed = seedClientRow();

    /* Act */
    const response = await post(page.request, `/user_clients/create/${targetId}`, { user_id: targetId, client_id: named });

    /* Assert */
    expect(redirectRoute(response)).toBe(`user_clients/user/${targetId}`);
    expect(userFlag(targetId)).toBe(0);
    expect(count('ip_user_clients', `user_id = ${targetId} AND client_id = ${named}`)).toBe(1);
    expect(count('ip_user_clients', `user_id = ${targetId} AND client_id = ${unnamed}`)).toBe(0);
  });

  test('it does not clobber an unrelated users all clients flag', async ({ page }) => {
    /* Arrange: without a WHERE on the ip_users update, every user's flag would reset to 0 */
    const bystanderId = seedAdminUser().userId;
    dbInsert('ip_user_clients', { user_id: bystanderId, client_id: seedClientRow() });
    dbExec(`UPDATE ip_users SET user_all_clients = 1 WHERE user_id = ${bystanderId}`);
    const targetId = seedAdminUser().userId;

    /* Act */
    const response = await post(page.request, `/user_clients/create/${targetId}`, { user_id: targetId, client_id: seedClientRow() });

    /* Assert */
    expect(redirectRoute(response)).toBe(`user_clients/user/${targetId}`);
    expect(userFlag(targetId)).toBe(0);
    expect(userFlag(bystanderId)).toBe(1);
  });

  test('it allows the primary admin to unassign another users client mapping via delete_user_client', async ({ page }) => {
    /* Arrange: the positive control for the secondary-admin refusal */
    const victimId = seedAdminUser().userId;
    const mappingId = dbInsert('ip_user_clients', { user_id: victimId, client_id: seedClientRow() });

    /* Act */
    const response = await post(page.request, `/users/delete_user_client/${victimId}/${mappingId}`);

    /* Assert */
    expect(redirectRoute(response)).toBe(`users/form/${victimId}`);
    expect(count('ip_user_clients', `user_client_id = ${mappingId}`)).toBe(0);
  });
});

test.describe('User clients — ids that are not plain integers', () => {
  test('it rejects a non-integer shaped user id in the create url', async ({ page }) => {
    /* Arrange: "2.9" authorises against user 2 via an (int) cast while the raw value is what gets written */
    const clientId = seedClientRow();

    /* Act */
    const response = await post(page.request, '/user_clients/create/2.9', { user_id: '2.9', client_id: clientId });

    /* Assert: rejected outright, not silently truncated to user 2 */
    expect(response.status()).toBe(403);
    expect(count('ip_user_clients', `client_id = ${clientId}`)).toBe(0);
  });

  test('it rejects a non-integer shaped url id even with no posted user id', async ({ page }) => {
    /* Arrange: the url guard must reject this on its own, the posted-id check only runs when the field is present */
    const clientId = seedClientRow();

    /* Act */
    const response = await post(page.request, '/user_clients/create/2.9', { client_id: clientId });

    /* Assert */
    expect(response.status()).toBe(403);
    expect(count('ip_user_clients', `client_id = ${clientId}`)).toBe(0);
  });

  test('it rejects a non-integer shaped posted user id in the create url', async ({ page }) => {
    /* Arrange: the url id is a genuine integer the admin may manage, the posted user_id is not */
    const targetId = seedAdminUser().userId;
    const clientId = seedClientRow();

    /* Act */
    const response = await post(page.request, `/user_clients/create/${targetId}`, { user_id: `${targetId}.9`, client_id: clientId });

    /* Assert */
    expect(response.status()).toBe(403);
    expect(count('ip_user_clients', `client_id = ${clientId}`)).toBe(0);
  });
});
