/**
 * Browser-side mirror of tests/Feature/Core/Users/UsersDeleteAuthorizationTest.php (GHSA-6r23-8rf2-78h3): Users::delete()
 * is limited to the primary administrator, under the production CSRF configuration. Every victim class is covered
 * (guest-type user, peer admin, the primary admin, the peer itself, an id that does not exist), the primary admin's own
 * deletion is the positive control. ("A deleted administrator's open session stops working" is not mirrored: see the
 * manifest.)
 */

import { test, expect } from '../test.js';
import { E2E_CSRF_BASE_URL } from '../config.js';
import { loginAs } from '../support/auth.js';
import { dbInsert } from '../support/db.js';
import { csrfTokenFrom } from '../support/csrf.js';
import { seedUser } from '../support/fixtures.js';
import { count, seedAdminUser, seedClientRow } from '../support/quotes.js';

const redirectRoute = (response) => new URL(response.headers().location, 'http://localhost').pathname.replace(/^\/index\.php/, '').replace(/^\/|\/$/g, '');

/** Log a peer administrator in on the CSRF server and return a way to POST as them with a token their page rendered. */
async function peerAdmin(browser) {
  const admin = seedAdminUser();
  const { context, page } = await loginAs(browser, admin.email, 'password', E2E_CSRF_BASE_URL);
  const deleteUser = async (id) => context.request.post(`/users/delete/${id}`, { form: { _ip_csrf: await csrfTokenFrom(page) }, maxRedirects: 0 });

  return { ...admin, context, page, deleteUser };
}

const userExists = (id) => count('ip_users', `user_id = ${id}`) === 1;

test.describe('Users delete — a peer admin is refused', () => {
  test('it denies a peer admin deleting a guest user and keeps the guests client assignments', async ({ browser }) => {
    /* Arrange */
    const peer = await peerAdmin(browser);
    const guest = seedUser({ user_type: 2, user_name: 'victim-guest' });
    dbInsert('ip_user_clients', { user_id: guest.id, client_id: seedClientRow() });

    /* Act */
    const response = await peer.deleteUser(guest.id);

    /* Assert */
    expect(response.status()).toBe(403);
    expect(count('ip_users', `user_id = ${guest.id} AND user_name = 'victim-guest'`)).toBe(1);
    expect(count('ip_user_clients', `user_id = ${guest.id}`)).toBe(1);
    await peer.context.close();
  });

  test('it denies a peer admin deleting the primary admin with 403 rather than a silent redirect', async ({ browser }) => {
    /* Arrange */
    const peer = await peerAdmin(browser);

    /* Act */
    const response = await peer.deleteUser(1);

    /* Assert: the primary admin is protected by the role check, not only by the target check */
    expect(response.status()).toBe(403);
    expect(userExists(1)).toBe(true);
    expect(userExists(peer.userId)).toBe(true);
    await peer.context.close();
  });

  test('it denies a peer admin deleting its own account through this endpoint', async ({ browser }) => {
    /* Arrange */
    const peer = await peerAdmin(browser);

    /* Act */
    const response = await peer.deleteUser(peer.userId);

    /* Assert */
    expect(response.status()).toBe(403);
    expect(userExists(peer.userId)).toBe(true);
    await peer.context.close();
  });

  test('it answers 403 for a peer admin even when the target id does not exist', async ({ browser }) => {
    /* Arrange */
    const peer = await peerAdmin(browser);

    /* Act */
    const response = await peer.deleteUser(999999);

    /* Assert: authorization comes first, so the existence of ids is not probeable */
    expect(response.status()).toBe(403);
    expect(userExists(peer.userId)).toBe(true);
    await peer.context.close();
  });
});

test.describe('Users delete — the primary admin', () => {
  const asPrimary = async (page, id) => page.request.post(`/users/delete/${id}`, { form: { _ip_csrf: await csrfTokenFrom(page) }, maxRedirects: 0 });

  test('it lets the primary admin delete a peer admin and only that peer', async ({ page }) => {
    /* Arrange: the positive control for every denial above */
    const peer = seedAdminUser().userId;
    const other = seedAdminUser().userId;
    const guest = seedUser({ user_type: 2 }).id;

    /* Act */
    const response = await asPrimary(page, peer);

    /* Assert */
    expect(redirectRoute(response)).toBe('users');
    expect(userExists(peer)).toBe(false);
    expect(userExists(other)).toBe(true);
    expect(userExists(guest)).toBe(true);
    expect(userExists(1)).toBe(true);
  });

  test('it refuses to let even the primary admin delete the primary admin', async ({ page }) => {
    /* Act */
    const response = await asPrimary(page, 1);

    /* Assert */
    expect(redirectRoute(response)).toBe('users');
    expect(userExists(1)).toBe(true);
  });
});
